<?php

namespace Tests\Feature\Nodes;

use App\Mail\WorkflowEmail;
use App\Models\Credential;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class MessagingNodesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function emailGraph(array $config): array
    {
        return $this->graph(
            ['start' => 'core.manual_trigger', 'mail' => ['core.send_email', [
                'to' => '{{ trigger.email }}, ops@example.com',
                'subject' => 'Order {{ trigger.id }} shipped',
                'body' => 'Hi {{ trigger.name }}',
                ...$config,
            ]]],
            ['start->mail'],
        );
    }

    public function test_send_email_renders_the_message_and_sends_it_through_the_organization_smtp_server(): void
    {
        Mail::fake();
        User::factory()->orgAdmin()->create();
        Organization::current()->update(['mail' => ['host' => 'smtp.acme.test', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'robot@acme.test', 'from_name' => 'Acme']]);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->emailGraph(['bcc' => 'audit@example.com', 'reply_to' => 'help@acme.test'])), ['email' => 'ada@example.com', 'id' => 7, 'name' => 'Ada']);

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => $mail->mailer === 'organization'
            && $mail->hasTo('ada@example.com') && $mail->hasTo('ops@example.com') && $mail->hasBcc('audit@example.com')
            && $mail->hasReplyTo('help@acme.test') && $mail->hasSubject('Order 7 shipped') && $mail->body === 'Hi Ada' && ! $mail->isHtml);
        $this->assertSame('smtps', config('mail.mailers.organization.scheme'));
        $this->assertSame('robot@acme.test', config('mail.mailers.organization.from.address'));
        $this->assertSame(['ada@example.com', 'ops@example.com'], $this->stepFor($execution, 'mail')->output['to']);
    }

    public function test_send_email_uses_the_chosen_workspace_smtp_server(): void
    {
        Mail::fake();
        $workspace = Workspace::factory()->create();
        $credential = Credential::factory()->for($workspace)->smtp()->create(['key' => 'team_mail']);

        $this->runWorkflow($this->publishedWorkflow($this->emailGraph(['credential' => 'team_mail', 'format' => 'html']), $workspace), ['email' => 'ada@example.com', 'id' => 7]);

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => $mail->mailer === "workspace_smtp_{$credential->id}" && $mail->isHtml);
        $this->assertSame($credential->config['host'], config("mail.mailers.workspace_smtp_{$credential->id}.host"));
        $this->assertSame($credential->config['from_address'], config("mail.mailers.workspace_smtp_{$credential->id}.from.address"));
    }

    public function test_send_email_refuses_an_invalid_address_without_sending(): void
    {
        Mail::fake();

        $execution = $this->runWorkflow($this->publishedWorkflow($this->emailGraph([])), ['email' => 'not-an-address', 'id' => 7]);

        Mail::assertNothingSent();
        $this->assertSame('"not-an-address" is not a valid email address.', $execution->error['message']);
    }

    public function test_send_email_fails_when_the_smtp_server_is_another_kind_of_setting(): void
    {
        Mail::fake();
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['key' => 'api']);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->emailGraph(['credential' => 'api']), $workspace), ['email' => 'ada@example.com', 'id' => 7]);

        Mail::assertNothingSent();
        $this->assertSame('SMTP server "api" was not found in this workspace\'s authentication settings.', $execution->error['message']);
    }

    public function test_send_notification_notifies_active_recipients_in_the_app_and_by_email(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        [$ada, $grace] = User::factory()->orgMember()->count(2)->create();
        $gone = User::factory()->deactivated()->create();

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'notify' => ['core.send_notification', [
                'recipients' => [$ada->id, $grace->id, $gone->id],
                'title' => 'Order {{ trigger.id }} needs a look',
                'message' => 'It is over budget.',
                'link' => 'https://shop.example.com/orders/{{ trigger.id }}',
                'email' => true,
            ]]],
            ['start->notify'],
        ), $workspace), ['id' => 7]);

        foreach ([$ada, $grace] as $user) {
            Notification::assertSentTo($user, WorkflowNotification::class, fn (WorkflowNotification $notification, array $channels) => $channels === ['database']
                && $notification->toArray($user) === ['title' => 'Order 7 needs a look', 'body' => 'It is over budget.', 'url' => 'https://shop.example.com/orders/7', 'icon' => 'bell', 'workspace_id' => $workspace->id]);
            Notification::assertSentTo($user, WorkflowNotification::class, fn (WorkflowNotification $notification, array $channels) => $channels === ['mail']);
        }
        Notification::assertNotSentTo($gone, WorkflowNotification::class);
        $this->assertSame(['recipients' => [$ada->id, $grace->id], 'count' => 2], $this->stepFor($execution, 'notify')->output);
    }

    public function test_send_notification_without_a_link_opens_the_run_that_sent_it(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $ada = User::factory()->orgMember()->create();

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'notify' => ['core.send_notification', ['recipients' => [$ada->id], 'title' => 'Done', 'link' => '  ', 'email' => true]]],
            ['start->notify'],
        ), $workspace));

        $runUrl = route('workspaces.executions.show', [$workspace, $execution]);
        Notification::assertSentTo($ada, WorkflowNotification::class, fn (WorkflowNotification $notification, array $channels) => $channels === ['database'] && $notification->toArray($ada)['url'] === $runUrl);
        Notification::assertSentTo($ada, WorkflowNotification::class, fn (WorkflowNotification $notification, array $channels) => $channels === ['mail'] && $notification->toMail($ada)->actionUrl === $runUrl);
    }

    public function test_send_notification_fails_without_an_active_recipient(): void
    {
        Notification::fake();
        $gone = User::factory()->deactivated()->create();

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'notify' => ['core.send_notification', ['recipients' => [$gone->id], 'title' => 'Hello']]],
            ['start->notify'],
        )));

        Notification::assertNothingSent();
        $this->assertSame('None of the recipients has an active account in this organization.', $execution->error['message']);
    }
}
