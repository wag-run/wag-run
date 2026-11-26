<?php

namespace Tests\Feature\Runs;

use App\Enums\AssignmentStatus;
use App\Enums\ExecutionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Assignment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AssignmentNotification;
use App\Services\Assignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $workspace = Workspace::factory()->create();
        $approver = User::factory()->orgMember()->create();

        $assignment = $this->startApproval($workspace, $approver);

        $this->assertSame($assignment->id, $approver->notifications()->where('type', AssignmentNotification::class)->sole()->data['assignment_id']);
        $this->assertSame(WorkspaceRole::Guest, $approver->roleIn($workspace));

        $this->actingAs($approver)->get(route('workspaces.show', $workspace))->assertOk()->assertSee('Refund order 42');
        $this->actingAs($approver)->get(route('inbox'))->assertOk()->assertSee('Refund order 42');

        Livewire::actingAs($approver)->test('pages::inbox.show', ['workspace' => $workspace, 'assignment' => $assignment])
            ->assertDontSee('Run #')
            ->set('comment', 'Fine by me')
            ->call('approve')
            ->assertHasNoErrors();

        $assignment->refresh();
        $this->assertSame(AssignmentStatus::Approved, $assignment->status);
        $this->actingAs($approver)->get(route('workspaces.inbox.show', [$workspace, $assignment]))
            ->assertSee('Answered by '.$approver->name);
        $this->assertSame('Fine by me', $assignment->response['comment']);
        $this->assertSame(ExecutionStatus::Complete, $assignment->execution->status);
    }

    public function test_the_user_menu_links_to_the_inbox_and_counts_what_waits_there(): void
    {
        $workspace = Workspace::factory()->create();
        $approver = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $this->actingAs($approver)->get(route('workspaces.show', $workspace))
            ->assertSee('data-test="inbox-link"', false)
            ->assertDontSee('data-test="inbox-count"', false)
            ->assertDontSee('Account menu,');

        $this->startApproval($workspace, $approver);

        $this->actingAs($approver)->get(route('workspaces.show', $workspace))
            ->assertSee('href="'.route('inbox').'"', false)
            ->assertSeeInOrder(['data-test="inbox-count">', '1', '</div>'], false)
            ->assertSee('Account menu, 1 unread item in your inbox');
    }

    public function test_a_task_form_is_validated_before_the_run_continues(): void
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

        $page->set('values.tracking', 'ZX-1')->call('submit')->assertHasNoErrors();

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

    public function test_approvals_and_tasks_in_the_inbox_say_whether_they_need_action_and_open_the_request(): void
    {
        $workspace = Workspace::factory()->create();
        $approver = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $approval = $this->startApproval($workspace, $approver);
        $this->travel(1)->minute();
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'check' => ['core.task', ['assignee' => $approver->id, 'title' => 'Count the pallets']]],
            ['start->check'],
        ), $workspace));
        $task = Assignment::query()->where('title', 'Count the pallets')->sole();

        Livewire::actingAs($approver)->test('pages::inbox.index')
            ->assertSeeInOrder(['Count the pallets', 'Action needed', 'Refund order 42', 'Action needed'])
            ->call('open', $approver->notifications()->where('data->assignment_id', $approval->id)->sole()->id)
            ->assertRedirect(route('workspaces.inbox.show', [$workspace, $approval]));

        app(Assignments::class)->submit($task, $approver, []);
        app(Assignments::class)->approve($approval, $approver);

        Livewire::actingAs($approver)->test('pages::inbox.index')
            ->assertSeeInOrder(['Count the pallets', 'Done', 'Refund order 42', 'Approved'])
            ->assertDontSee('Action needed');

        $approval->update(['status' => AssignmentStatus::Rejected]);
        $task->update(['status' => AssignmentStatus::Expired]);

        Livewire::actingAs($approver)->test('pages::inbox.index')
            ->assertSeeInOrder(['Count the pallets', 'Expired', 'Refund order 42', 'Rejected'])
            ->assertDontSeeHtml('data-test="done"');
    }

    public function test_opening_a_request_marks_its_inbox_item_read(): void
    {
        $workspace = Workspace::factory()->create();
        $approver = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $assignment = $this->startApproval($workspace, $approver);

        $this->actingAs($approver)->get(route('workspaces.inbox.show', [$workspace, $assignment]))->assertOk();

        $this->assertSame(0, $approver->unreadNotifications()->count());
        $this->assertTrue($assignment->refresh()->isPending());
    }

    public function test_old_inbox_and_notifications_links_lead_to_the_inbox(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $this->actingAs($user)->get(route('workspaces.inbox', $workspace))->assertRedirect(route('inbox'));
        $this->actingAs($user)->get(route('notifications'))->assertRedirect(route('inbox'));
    }

    public function test_an_approval_sent_to_several_people_shows_every_approver_and_where_it_stands(): void
    {
        $workspace = Workspace::factory()->create();
        [$ada, $grace, $linus] = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->count(3)->create();
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'approve' => ['core.approval', ['approvers' => [$ada->id, $grace->id, $linus->id], 'required_approvals' => 2, 'title' => 'Budget']]],
            ['start->approve'],
        ), $workspace));
        $request = fn (User $user) => Assignment::query()->where('assignee_id', $user->id)->sole();

        Livewire::actingAs($ada)->test('pages::inbox.show', ['workspace' => $workspace, 'assignment' => $request($ada)])
            ->set('comment', 'Looks fine')
            ->call('approve')
            ->assertSeeInOrder([$ada->name, '(you)', 'Looks fine', 'Approved', $grace->name, 'Waiting', $linus->name, 'Waiting'])
            ->assertSee('1 of 2 approvals so far');

        app(Assignments::class)->reject($request($grace), $grace, 'Too much');
        app(Assignments::class)->approve($request($linus), $linus);

        Livewire::actingAs($ada)->test('pages::inbox.show', ['workspace' => $workspace, 'assignment' => $request($ada)])
            ->assertSeeInOrder([$ada->name, 'Approved', $grace->name, 'Too much', 'Rejected', $linus->name, 'Approved'])
            ->assertSee('Approved: 2 of 2 needed')
            ->assertDontSeeHtml('data-test="answered"');
    }

    public function test_an_approval_for_one_person_has_no_approvers_list(): void
    {
        $workspace = Workspace::factory()->create();
        $approver = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $assignment = $this->startApproval($workspace, $approver);

        Livewire::actingAs($approver)->test('pages::inbox.show', ['workspace' => $workspace, 'assignment' => $assignment])
            ->assertDontSeeHtml('data-test="approvers"');
    }

    public function test_once_an_approval_is_decided_the_requests_still_waiting_show_why_they_closed(): void
    {
        $workspace = Workspace::factory()->create();
        [$ada, $grace] = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->count(2)->create();
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'approve' => ['core.approval', ['approvers' => [$ada->id, $grace->id], 'required_approvals' => 1, 'title' => 'Budget']]],
            ['start->approve'],
        ), $workspace));

        app(Assignments::class)->approve(Assignment::query()->where('assignee_id', $ada->id)->sole(), $ada);
        $withdrawn = Assignment::query()->where('assignee_id', $grace->id)->sole();

        Livewire::actingAs($grace)->test('pages::inbox.show', ['workspace' => $workspace, 'assignment' => $withdrawn])
            ->assertSeeInOrder([$ada->name, 'Approved', $grace->name, '(you)', 'Withdrawn'])
            ->assertSee('Approved: 1 of 1 needed')
            ->assertDontSeeHtml('data-test="approve-button"');
    }
}
