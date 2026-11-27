<?php

namespace Tests\Feature;

use App\Engine\Engine;
use App\Enums\ExecutionStatus;
use App\Mail\WorkflowEmail;
use App\Models\Assignment;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Notifications\WorkflowNotification;
use App\Services\Assignments;
use App\Support\HostResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DemoWorkflowSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $input
     */
    private function start(string $name, array $input = []): Execution
    {
        return app(Engine::class)->start(Workflow::firstWhere('name', $name), $input)->refresh();
    }

    private function admin(): User
    {
        return User::firstWhere('email', 'test@example.com');
    }

    public function test_seeding_publishes_the_demo_workflows_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['Daily post digest', 'Expense report', 'New hire onboarding', 'Order status updates', 'Refund approval'],
            Workflow::query()->whereNotNull('published_version_id')->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_small_refunds_are_approved_automatically_and_large_ones_wait_for_an_approver(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);

        $small = $this->start('Refund approval', ['values' => ['customer' => 'Ada', 'email' => 'ada@example.com', 'amount' => 40]]);
        $large = $this->start('Refund approval', ['values' => ['customer' => 'Grace', 'email' => 'grace@example.com', 'amount' => 250]]);

        $this->assertSame(ExecutionStatus::Complete, $small->status);
        $this->assertSame(ExecutionStatus::Waiting, $large->status);
        $this->assertSame(['No reason given.'], Assignment::query()->distinct()->pluck('message')->all());

        app(Assignments::class)->approve(Assignment::query()->where('assignee_id', $this->admin()->id)->sole(), $this->admin());

        $this->assertSame(ExecutionStatus::Complete, $large->refresh()->status);
        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => $mail->hasTo('grace@example.com') && $mail->subjectLine === 'Your refund of 250.00 is on its way');
    }

    public function test_order_updates_follow_the_status_and_ignore_unknown_ones(): void
    {
        Mail::fake();
        Notification::fake();
        $this->seed(DatabaseSeeder::class);

        $this->start('Order status updates', ['body' => ['order_id' => 42, 'status' => 'Shipped', 'email' => 'ada@example.com']]);
        $this->start('Order status updates', ['body' => ['order_id' => 43, 'status' => 'cancelled', 'email' => 'ada@example.com']]);
        $ignored = $this->start('Order status updates', ['body' => ['order_id' => 44, 'status' => 'refunded']]);

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => $mail->subjectLine === 'Order #42 has shipped');
        Notification::assertSentTo($this->admin(), WorkflowNotification::class, fn (WorkflowNotification $notification) => $notification->title === 'Order #43 was cancelled');
        $this->assertSame('Nothing to do for status "refunded".', $ignored->output);
    }

    public function test_onboarding_waits_for_it_then_welcomes_and_announces_in_parallel(): void
    {
        Mail::fake();
        Notification::fake();
        $this->seed(DatabaseSeeder::class);

        $execution = $this->start('New hire onboarding', ['values' => ['name' => 'Linus', 'email' => 'linus@example.com', 'team' => 'eng', 'start_date' => '2026-12-01']]);
        $task = Assignment::sole();

        $this->assertSame('Set up accounts and a laptop for Linus', $task->title);
        $this->assertSame('Starts Tuesday 1 December on the eng team.', $task->message);

        app(Assignments::class)->submit($task, User::firstWhere('email', 'editor@example.com'), ['laptop' => 'MBP-001']);

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => $mail->hasTo('linus@example.com'));
        Notification::assertSentTo($this->admin(), WorkflowNotification::class, fn (WorkflowNotification $notification) => $notification->body === 'Laptop MBP-001, prepared by Test Editor.');
        $this->assertSame('Linus onboarded: welcome email sent to linus@example.com.', $execution->steps()->where('node_id', 'summary')->sole()->output['value']);
    }

    public function test_post_digest_formats_each_post_into_one_notification(): void
    {
        Notification::fake();
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
        Http::fake(['jsonplaceholder.typicode.com/posts*' => Http::response([['id' => 1, 'title' => 'first post'], ['id' => 2, 'title' => 'second post']])]);
        $this->seed(DatabaseSeeder::class);

        $this->start('Daily post digest');

        Notification::assertSentTo($this->admin(), WorkflowNotification::class, fn (WorkflowNotification $notification) => $notification->title === '2 new posts'
            && $notification->body === "1. First post\n2. Second post");
    }

    public function test_expense_report_totals_the_amounts_and_lists_those_over_the_limit_with_variables(): void
    {
        Notification::fake();
        $this->seed(DatabaseSeeder::class);

        $execution = $this->start('Expense report', ['values' => ['employee' => 'Ada', 'amounts' => '23.50, 140, 10']]);
        $none = $this->start('Expense report', ['values' => ['employee' => 'Grace', 'amounts' => '5']]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertEquals(
            ['total' => 173.5, 'over_limit' => ['140'], 'limit' => 100],
            $execution->variables()->get()->mapWithKeys(fn ($variable) => [$variable->name => $variable->decoded()])->all(),
        );
        Notification::assertSentTo($this->admin(), WorkflowNotification::class, fn (WorkflowNotification $notification) => $notification->title === 'Ada claimed 173.50'
            && $notification->body === 'Amounts: 3. Over 100: 140.');
        Notification::assertSentTo($this->admin(), WorkflowNotification::class, fn (WorkflowNotification $notification) => $notification->title === 'Grace claimed 5.00'
            && $notification->body === 'Amounts: 1. Over 100: none.');
        $this->assertSame(ExecutionStatus::Complete, $none->status);
    }
}
