<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Mail\WorkflowEmail;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkflowNotification;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
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
                'to' => '{{ trigger.values.email }}, ops@example.com',
                'subject' => 'Order {{ trigger.values.id }} shipped',
                'body' => 'Hi {{ trigger.values.name }}',
                ...$config,
            ]]],
            ['start->mail'],
        );
    }

    public function test_send_email_renders_the_message_and_sends_it_through_the_organization_smtp_server(): void
    {
        Mail::fake();
        User::factory()->orgAdmin()->create();
        $this->organization()->update(['mail' => ['host' => 'smtp.acme.test', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'robot@acme.test', 'from_name' => 'Acme']]);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->emailGraph(['bcc' => 'audit@example.com', 'reply_to' => 'help@acme.test'])), ['values' => ['email' => 'ada@example.com', 'id' => 7, 'name' => 'Ada']]);

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
        $credential = Credential::factory()->for($workspace)->smtp()->create(['id' => $this->settingId('team_mail'), 'description' => 'team_mail']);

        $this->runWorkflow($this->publishedWorkflow($this->emailGraph(['credential' => $this->settingId('team_mail'), 'format' => 'html']), $workspace), ['values' => ['email' => 'ada@example.com', 'id' => 7]]);

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => $mail->mailer === "workspace_smtp_{$credential->id}" && $mail->isHtml);
        $this->assertSame($credential->config['host'], config("mail.mailers.workspace_smtp_{$credential->id}.host"));
        $this->assertSame($credential->config['from_address'], config("mail.mailers.workspace_smtp_{$credential->id}.from.address"));
    }

    public function test_send_email_refuses_an_invalid_address_without_sending(): void
    {
        Mail::fake();

        $execution = $this->runWorkflow($this->publishedWorkflow($this->emailGraph([])), ['values' => ['email' => 'not-an-address', 'id' => 7]]);

        Mail::assertNothingSent();
        $this->assertSame('"not-an-address" is not a valid email address.', $execution->error['message']);
    }

    public function test_send_email_fails_when_the_smtp_server_is_another_kind_of_setting(): void
    {
        Mail::fake();
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('api'), 'description' => 'api']);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->emailGraph(['credential' => $this->settingId('api')]), $workspace), ['values' => ['email' => 'ada@example.com', 'id' => 7]]);

        Mail::assertNothingSent();
        $this->assertSame('The SMTP server chosen on this node no longer exists. Choose another in the node\'s settings.', $execution->error['message']);
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
                'title' => 'Order {{ trigger.values.id }} needs a look',
                'message' => 'It is over budget.',
                'link' => 'https://shop.example.com/orders/{{ trigger.values.id }}',
            ]]],
            ['start->notify'],
        ), $workspace), ['values' => ['id' => 7]]);

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
            ['start' => 'core.manual_trigger', 'notify' => ['core.send_notification', ['recipients' => [$ada->id], 'title' => 'Done', 'link' => '  ']]],
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

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function chatServices(): array
    {
        return [
            'slack' => ['slack', ['text' => 'Order 7 shipped']],
            'discord' => ['discord', ['content' => 'Order 7 shipped']],
            'google chat' => ['google_chat', ['text' => 'Order 7 shipped']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('chatServices')]
    public function test_chat_message_posts_the_payload_each_service_expects(string $service, array $payload): void
    {
        $this->fakeChatHost();
        Http::fake(['hooks.example.com/*' => Http::response('ok')]);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->chatWebhook($service)->create(['id' => $this->settingId('ops'), 'description' => 'ops']);

        $execution = $this->runChatMessage($workspace, 'Order {{ trigger.values.id }} shipped', ['id' => 7]);

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://hooks.example.com/services/T000/B000/secret' && $request->data() === $payload);
        $this->assertSame(['service' => $service, 'status' => 200], $this->stepFor($execution, 'chat')->output);
    }

    public function test_chat_message_sends_teams_an_adaptive_card(): void
    {
        $this->fakeChatHost();
        Http::fake(['hooks.example.com/*' => Http::response('', 202)]);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->chatWebhook('teams')->create(['id' => $this->settingId('ops'), 'description' => 'ops']);

        $this->runChatMessage($workspace, 'Deploy done');

        Http::assertSent(fn ($request) => $request['type'] === 'message'
            && $request['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
            && $request['attachments'][0]['content']['body'] === [['type' => 'TextBlock', 'text' => 'Deploy done', 'wrap' => true]]);
    }

    public function test_chat_message_reports_what_the_service_answered_without_the_webhook_url(): void
    {
        $this->fakeChatHost();
        Http::fake(['hooks.example.com/*' => Http::response('invalid_payload', 400)]);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->chatWebhook()->create(['id' => $this->settingId('ops'), 'description' => 'ops']);

        $execution = $this->runChatMessage($workspace, 'Hello');

        $this->assertSame('The chat service answered 400: invalid_payload', $execution->error['message']);
        $this->assertStringNotContainsString('B000/secret', json_encode($this->stepFor($execution, 'chat')->getAttributes()));
    }

    public function test_chat_message_refuses_a_webhook_on_an_internal_address(): void
    {
        Http::fake();
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->chatWebhook(url: 'http://127.0.0.1/hook')->create(['id' => $this->settingId('ops'), 'description' => 'ops']);

        $execution = $this->runChatMessage($workspace, 'Hello');

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        Http::assertNothingSent();
    }

    public function test_chat_message_fails_when_the_chat_webhook_is_missing(): void
    {
        Http::fake();
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('ops'), 'description' => 'ops']);

        $execution = $this->runChatMessage($workspace, 'Hello');

        $this->assertSame('The chat webhook chosen on this node no longer exists. Choose another in the node\'s settings.', $execution->error['message']);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function runChatMessage(Workspace $workspace, string $message, array $input = []): Execution
    {
        return $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'chat' => ['core.chat_message', ['credential' => $this->settingId('ops'), 'message' => $message]]],
            ['start->chat'],
        ), $workspace), ['values' => $input]);
    }

    private function fakeChatHost(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'hooks.example.com' ? ['93.184.215.20'] : parent::resolve($host);
            }
        });
    }
}
