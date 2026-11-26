<?php

namespace Tests\Feature\Settings;

use App\Enums\NotificationKind;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Notifications\AssignmentNotification;
use App\Notifications\RunFailedNotification;
use App\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private function failedRunFor(User $owner, Workspace $workspace): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'stop' => ['core.terminate', ['status' => 'failed', 'output' => 'Out of stock']]],
            ['start->stop'],
        ), $workspace);
        $workflow->update(['owner_id' => $owner->id]);

        $this->runWorkflow($workflow);
    }

    public function test_the_page_is_displayed_with_every_channel_on_by_default(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('notifications.edit'))->assertOk()->assertSee(NotificationKind::RunFailed->label());

        Livewire::test('pages::settings.notifications')
            ->assertSet('preferences.run_failed.database', true)
            ->assertSet('preferences.run_failed.mail', true);
    }

    public function test_preferences_can_be_saved(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::settings.notifications')
            ->set('preferences.run_failed.mail', false)
            ->set('preferences.assignment.database', false)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->wantsNotification(NotificationKind::RunFailed, 'mail'));
        $this->assertTrue($user->wantsNotification(NotificationKind::RunFailed, 'database'));
        $this->assertFalse($user->wantsNotification(NotificationKind::Assignment, 'database'));
        $this->assertTrue($user->wantsNotification(NotificationKind::Workflow, 'mail'));
    }

    public function test_a_failed_run_notifies_the_owner_in_the_app_and_by_email(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $this->failedRunFor($owner, $workspace);

        Notification::assertSentTo($owner, RunFailedNotification::class, fn (RunFailedNotification $n, array $channels) => $channels === ['database']);
        Notification::assertSentTo($owner, RunFailedNotification::class, fn (RunFailedNotification $n, array $channels) => $channels === ['mail']);
    }

    public function test_a_failed_run_respects_the_owners_channel_choices(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $owner->forceFill(['notification_preferences' => ['run_failed' => ['database' => true, 'mail' => false]]])->save();

        $this->failedRunFor($owner, $workspace);

        Notification::assertSentTo($owner, RunFailedNotification::class, fn (RunFailedNotification $n, array $channels) => $channels === ['database']);
        Notification::assertNotSentTo($owner, RunFailedNotification::class, fn (RunFailedNotification $n, array $channels) => $channels === ['mail']);

        Notification::fake();
        $owner->forceFill(['notification_preferences' => ['run_failed' => ['database' => false, 'mail' => true]]])->save();

        $this->failedRunFor($owner, $workspace);

        Notification::assertNotSentTo($owner, RunFailedNotification::class, fn (RunFailedNotification $n, array $channels) => $channels === ['database']);
        Notification::assertSentTo($owner, RunFailedNotification::class, fn (RunFailedNotification $n, array $channels) => $channels === ['mail']);
    }

    public function test_the_failed_run_email_names_the_run_and_links_to_it(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $this->failedRunFor($owner, $workspace);
        $execution = Workflow::query()->where('owner_id', $owner->id)->firstOrFail()->executions()->firstOrFail();

        $mail = (new RunFailedNotification($execution, ['mail']))->toMail($owner);

        $this->assertStringContainsString('failed', $mail->subject);
        $this->assertSame([(string) ($execution->error['message'] ?? 'Run failed')], $mail->introLines);
        $this->assertSame(route('workspaces.executions.show', [$workspace, $execution]), $mail->actionUrl);
    }

    public function test_workflow_notifications_and_assignments_skip_the_channels_a_person_turned_off(): void
    {
        Notification::fake();
        $recipient = User::factory()->orgMember()->create();
        $recipient->forceFill(['notification_preferences' => [
            'assignment' => ['database' => false, 'mail' => true],
            'workflow' => ['database' => true, 'mail' => false],
        ]])->save();

        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'notify' => ['core.send_notification', ['recipients' => [$recipient->id], 'title' => 'Heads up', 'email' => true]]],
            ['start->notify'],
        )));
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'approve' => ['core.approval', ['approvers' => [$recipient->id], 'title' => 'Refund', 'email' => true]]],
            ['start->approve'],
        )));

        $channelsOf = fn (string $class) => Notification::sent($recipient, $class)->map(fn ($notification) => $notification->channels)->all();
        $this->assertSame([['database']], $channelsOf(WorkflowNotification::class));
        $this->assertSame([['mail']], $channelsOf(AssignmentNotification::class));
    }
}
