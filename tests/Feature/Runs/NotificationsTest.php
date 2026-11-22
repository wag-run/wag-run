<?php

namespace Tests\Feature\Runs;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Notifications\RunFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private function workflowOwnedBy(User $owner, Workspace $workspace, string $endStatus): Workflow
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'stop' => ['core.terminate', ['status' => $endStatus, 'output' => 'Out of stock']]],
            ['start->stop'],
        ), $workspace);
        $workflow->update(['owner_id' => $owner->id]);

        return $workflow;
    }

    public function test_the_owner_is_notified_when_a_run_fails_and_not_when_it_completes(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $this->runWorkflow($this->workflowOwnedBy($owner, $workspace, 'complete'));
        Notification::assertNothingSent();

        $failed = $this->runWorkflow($this->workflowOwnedBy($owner, $workspace, 'failed'));
        Notification::assertSentTo($owner, RunFailedNotification::class, fn (RunFailedNotification $notification) => $notification->execution->is($failed));
    }

    public function test_an_owner_who_can_no_longer_see_the_runs_is_not_notified(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Guest)->create();

        $this->runWorkflow($this->workflowOwnedBy($owner, $workspace, 'failed'));

        Notification::assertNothingSent();
    }

    public function test_opening_a_notification_marks_it_read_and_goes_to_the_run(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $failed = $this->runWorkflow($this->workflowOwnedBy($owner, $workspace, 'failed'));
        $notification = $owner->unreadNotifications()->sole();

        $this->actingAs($owner)->get(route('notifications'))->assertOk()->assertSee('Run #'.substr(str_replace('-', '', $failed->id), -8).' of');

        Livewire::actingAs($owner)->test('pages::notifications.index')
            ->call('open', $notification->id)
            ->assertRedirect(route('workspaces.executions.show', [$workspace, $failed]));

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_people_cannot_open_someone_elses_notification(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $this->runWorkflow($this->workflowOwnedBy($owner, $workspace, 'failed'));
        $someoneElse = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Livewire::actingAs($someoneElse)->test('pages::notifications.index')
            ->call('open', $owner->notifications()->sole()->id)
            ->assertNotFound();
    }
}
