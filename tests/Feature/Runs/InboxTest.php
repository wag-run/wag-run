<?php

namespace Tests\Feature\Runs;

use App\Enums\AssignmentStatus;
use App\Enums\ExecutionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Assignment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AssignmentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private function startApproval(Workspace $workspace, User $approver): Assignment
    {
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'approve' => ['core.approval', ['approvers' => [$approver->id], 'title' => 'Refund order 42']],
                'refund' => ['core.compose', ['template' => 'refunded']]],
            ['start->approve', 'approve:approved->refund'],
        ), $workspace));

        return Assignment::sole();
    }

    public function test_a_guest_assignee_is_notified_sees_it_in_their_inbox_and_approves_it(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $approver = User::factory()->orgMember()->create();

        $assignment = $this->startApproval($workspace, $approver);

        Notification::assertSentTo($approver, AssignmentNotification::class, fn (AssignmentNotification $notification) => $notification->assignment->is($assignment));
        $this->assertSame(WorkspaceRole::Guest, $approver->roleIn($workspace));

        $this->actingAs($approver)->get(route('workspaces.show', $workspace))->assertOk()->assertSee('Refund order 42');
        $this->actingAs($approver)->get(route('workspaces.inbox', $workspace))->assertOk()->assertSee('Refund order 42');

        Livewire::actingAs($approver)->test('pages::inbox.show', ['workspace' => $workspace, 'assignment' => $assignment])
            ->assertDontSee('Run #')
            ->set('comment', 'Fine by me')
            ->call('approve')
            ->assertHasNoErrors();

        $assignment->refresh();
        $this->assertSame(AssignmentStatus::Approved, $assignment->status);
        $this->assertSame('Fine by me', $assignment->response['comment']);
        $this->assertSame(ExecutionStatus::Complete, $assignment->execution->status);
    }

    public function test_a_task_form_is_validated_before_the_run_continues_and_its_notification_is_cleared(): void
    {
        $workspace = Workspace::factory()->create();
        $assignee = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'collect' => ['core.task', ['assignee' => $assignee->id, 'title' => 'Ship it', 'fields' => [['name' => 'tracking', 'label' => 'Tracking number', 'type' => 'text', 'required' => true]]]]],
            ['start->collect'],
        ), $workspace));
        $assignment = Assignment::sole();

        $page = Livewire::actingAs($assignee)->test('pages::inbox.show', ['workspace' => $workspace, 'assignment' => $assignment])
            ->assertSee('Run #')
            ->call('submit')
            ->assertHasErrors('values.tracking');

        $this->assertTrue($assignment->refresh()->isPending());
        $this->assertSame(1, $assignee->unreadNotifications()->count());

        $page->set('values.tracking', 'ZX-1')->call('submit')->assertHasNoErrors();

        $this->assertSame(0, $assignee->unreadNotifications()->count());
        $this->assertSame(['values' => ['tracking' => 'ZX-1']], $assignment->refresh()->response);
        $this->assertSame(ExecutionStatus::Complete, $assignment->execution->status);
    }

    public function test_only_the_assignee_can_open_an_assignment_and_only_in_its_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $approver = User::factory()->orgMember()->create();
        $assignment = $this->startApproval($workspace, $approver);
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $otherWorkspace = Workspace::factory()->create();
        $approver->workspaces()->attach($otherWorkspace, ['role' => WorkspaceRole::Guest->value]);

        $this->actingAs($admin)->get(route('workspaces.inbox.show', [$workspace, $assignment]))->assertForbidden();
        $this->actingAs($approver)->get(route('workspaces.inbox.show', [$otherWorkspace, $assignment]))->assertNotFound();
        $this->actingAs($approver)->get(route('workspaces.inbox.show', [$workspace, $assignment]))->assertOk();
    }
}
