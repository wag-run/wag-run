<?php

namespace Tests\Feature\Nodes;

use App\Engine\Engine;
use App\Enums\AssignmentStatus;
use App\Enums\ExecutionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Assignment;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Notifications\AssignmentNotification;
use App\Services\Assignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class ApprovalAndTaskTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private function approvalWorkflow(User $approver, ?Workspace $workspace = null): Workflow
    {
        return $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'approve' => ['core.approval', ['approvers' => [$approver->id], 'title' => 'Refund {{ trigger.amount }}', 'message' => 'For {{ trigger.customer }}']],
                'refund' => ['core.compose', ['template' => 'refunded']],
                'decline' => ['core.compose', ['template' => 'declined']],
                'escalate' => ['core.compose', ['template' => '{{ error.message }}']]],
            ['start->approve', 'approve:approved->refund', 'approve:rejected->decline', 'approve:error->escalate'],
        ), $workspace);
    }

    public function test_approval_waits_for_the_named_user_and_follows_their_decision(): void
    {
        $this->freezeSecond();
        $approver = User::factory()->orgMember()->create();
        $workflow = $this->approvalWorkflow($approver);

        $execution = $this->runWorkflow($workflow, ['amount' => 120, 'customer' => 'Acme']);
        $assignment = Assignment::sole();

        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertSame('Refund 120', $assignment->title);
        $this->assertSame('For Acme', $assignment->message);
        $this->assertTrue($assignment->due_at->eq(now()->addHours(48)));

        app(Assignments::class)->approve($assignment, $approver, 'Looks fine');

        $execution->refresh();
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['start', 'approve', 'refund'], $this->nodesRun($execution));
        $this->assertSame([
            'decision' => 'approved', 'comment' => 'Looks fine', 'responded_by' => $approver->id, 'responded_by_name' => $approver->name,
            'approvals' => 1, 'rejections' => 0,
            'responses' => [['user_id' => $approver->id, 'name' => $approver->name, 'decision' => 'approved', 'comment' => 'Looks fine']],
        ], $this->stepFor($execution, 'approve')->output);
        $this->assertSame(AssignmentStatus::Approved, $assignment->refresh()->status);
    }

    /**
     * @param  list<User>  $approvers
     */
    private function multiApprovalWorkflow(array $approvers, int $required): Workflow
    {
        return $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'approve' => ['core.approval', ['approvers' => array_map(fn (User $user) => $user->id, $approvers), 'required_approvals' => $required, 'title' => 'Budget']],
                'go' => ['core.compose', ['template' => 'go']],
                'stop' => ['core.compose', ['template' => 'stop']]],
            ['start->approve', 'approve:approved->go', 'approve:rejected->stop'],
        ));
    }

    public function test_with_two_of_three_approvals_needed_a_rejection_does_not_decide_while_two_approvals_can_still_happen(): void
    {
        [$ada, $grace, $linus] = User::factory()->orgMember()->count(3)->create();
        $execution = $this->runWorkflow($this->multiApprovalWorkflow([$ada, $grace, $linus], 2));
        $requests = Assignment::query()->orderBy('id')->get()->keyBy('assignee_id');
        $this->assertCount(3, $requests);

        app(Assignments::class)->approve($requests[$ada->id], $ada, 'Yes');
        $this->assertSame(ExecutionStatus::Waiting, $execution->refresh()->status);

        app(Assignments::class)->reject($requests[$linus->id], $linus, 'No');
        $this->assertSame(ExecutionStatus::Waiting, $execution->refresh()->status, 'two approvals can still happen');

        app(Assignments::class)->approve($requests[$grace->id], $grace);

        $this->assertSame(['start', 'approve', 'go'], $this->nodesRun($execution));
        $output = $this->stepFor($execution, 'approve')->output;
        $this->assertSame(['approved', 2, 1, $grace->id], [$output['decision'], $output['approvals'], $output['rejections'], $output['responded_by']]);
        $this->assertSame([$ada->id, $grace->id, $linus->id], array_column($output['responses'], 'user_id'), 'answers are listed in approver order');
        $this->assertSame(AssignmentStatus::Approved, $requests[$grace->id]->refresh()->status);
    }

    public function test_approval_is_rejected_once_the_needed_approvals_can_no_longer_be_reached(): void
    {
        [$ada, $grace, $linus] = User::factory()->orgMember()->count(3)->create();
        $execution = $this->runWorkflow($this->multiApprovalWorkflow([$ada, $grace, $linus], 2));
        $requests = Assignment::query()->get()->keyBy('assignee_id');

        app(Assignments::class)->reject($requests[$ada->id], $ada);
        app(Assignments::class)->reject($requests[$grace->id], $grace);

        $this->assertSame(['start', 'approve', 'stop'], $this->nodesRun($execution));
        $this->assertSame(AssignmentStatus::Cancelled, $requests[$linus->id]->refresh()->status);
    }

    public function test_approval_fails_when_it_needs_more_approvals_than_active_approvers(): void
    {
        [$ada, $grace] = User::factory()->orgMember()->count(2)->create();

        $execution = $this->runWorkflow($this->multiApprovalWorkflow([$ada, $grace], 3));

        $this->assertSame('Needs 3 approvals, but only 2 approvers have an active account.', $execution->error['message']);
        $this->assertSame(0, Assignment::count());
    }

    public function test_rejection_follows_the_rejected_path(): void
    {
        $approver = User::factory()->orgMember()->create();
        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        app(Assignments::class)->reject(Assignment::sole(), $approver);

        $this->assertSame(['start', 'approve', 'decline'], $this->nodesRun($execution));
    }

    public function test_assignee_outside_the_workspace_joins_as_a_guest(): void
    {
        $workspace = Workspace::factory()->create();
        $outsider = User::factory()->orgMember()->create();

        $this->runWorkflow($this->approvalWorkflow($outsider, $workspace));

        $this->assertSame(WorkspaceRole::Guest, $outsider->roleIn($workspace));
    }

    public function test_only_the_assignee_can_answer_and_only_once(): void
    {
        $approver = User::factory()->orgMember()->create();
        $someoneElse = User::factory()->orgMember()->create();
        $this->runWorkflow($this->approvalWorkflow($approver));
        $assignment = Assignment::sole();

        try {
            app(Assignments::class)->approve($assignment, $someoneElse);
            $this->fail('A non-assignee approved.');
        } catch (ValidationException $e) {
            $this->assertSame('Only the assignee can respond.', $e->errors()['assignment'][0]);
        }

        app(Assignments::class)->reject($assignment, $approver);

        $this->expectException(ValidationException::class);
        app(Assignments::class)->approve($assignment->refresh(), $approver);
    }

    public function test_unanswered_approval_times_out_into_the_error_output(): void
    {
        $approver = User::factory()->orgMember()->create();
        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        $this->travel(49)->hours();
        $this->sweep();

        $this->assertSame(AssignmentStatus::Expired, Assignment::sole()->status);
        $this->assertSame('No response before the approval timed out.', $this->stepFor($execution, 'escalate')->output['value']);
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_cancelling_the_run_withdraws_open_assignments(): void
    {
        $approver = User::factory()->orgMember()->create();
        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        app(Engine::class)->cancel($execution);

        $this->assertSame(AssignmentStatus::Cancelled, Assignment::sole()->status);
    }

    public function test_approvers_are_emailed_only_when_the_node_asks_for_it(): void
    {
        Notification::fake();
        [$emailed, $notEmailed] = User::factory()->orgMember()->count(2)->create();

        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'approve' => ['core.approval', ['approvers' => [$emailed->id], 'title' => 'Refund', 'email' => true]]],
            ['start->approve'],
        )));
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'check' => ['core.task', ['assignee' => $notEmailed->id, 'title' => 'Check stock']]],
            ['start->check'],
        )));

        $byMail = fn (AssignmentNotification $notification, array $channels) => $channels === ['mail'];
        Notification::assertSentTo($emailed, AssignmentNotification::class, fn (AssignmentNotification $notification, array $channels) => $channels === ['database']);
        Notification::assertSentTo($emailed, AssignmentNotification::class, $byMail);
        Notification::assertSentTo($notEmailed, AssignmentNotification::class, fn (AssignmentNotification $notification, array $channels) => $channels === ['database']);
        Notification::assertNotSentTo($notEmailed, AssignmentNotification::class, $byMail);
    }

    public function test_the_assignment_email_carries_the_request_due_date_and_a_link_to_it(): void
    {
        $this->freezeSecond();
        $approver = User::factory()->orgMember()->create();
        $workflow = $this->approvalWorkflow($approver);
        $this->runWorkflow($workflow, ['amount' => 120, 'customer' => 'Acme']);
        $assignment = Assignment::sole();

        $mail = (new AssignmentNotification($assignment, ['mail']))->toMail($approver);

        $this->assertSame('Approval requested: Refund 120', $mail->subject);
        $this->assertContains("{$workflow->name} in {$workflow->workspace->name} is waiting on you.", $mail->introLines);
        $this->assertContains('For Acme', $mail->introLines);
        $this->assertContains('Please respond by '.$assignment->due_at->toDayDateTimeString().'.', $mail->introLines);
        $this->assertSame('Review and respond', $mail->actionText);
        $this->assertSame(route('workspaces.inbox.show', [$workflow->workspace, $assignment]), $mail->actionUrl);
    }

    public function test_deactivated_assignee_fails_the_step(): void
    {
        User::factory()->orgAdmin()->create();
        $approver = User::factory()->deactivated()->create();

        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        $this->assertSame('None of the approvers has an active account in this organization.', $this->stepFor($execution, 'escalate')->output['value']);
        $this->assertSame(0, Assignment::count());
    }

    public function test_task_validates_the_form_and_returns_the_submitted_values(): void
    {
        $assignee = User::factory()->orgMember()->create();
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'check' => ['core.task', ['assignee' => $assignee->id, 'title' => 'Check stock', 'fields' => [
                    ['name' => 'count', 'label' => 'Count', 'type' => 'number', 'required' => true],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ]]],
                'after' => ['core.compose', ['template' => '{{ nodes.check.output.values.count }} left']]],
            ['start->check', 'check->after'],
        ));
        $execution = $this->runWorkflow($workflow);
        $assignment = Assignment::sole();

        try {
            app(Assignments::class)->submit($assignment, $assignee, ['notes' => 'no count']);
            $this->fail('The required field was not enforced.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('count', $e->errors());
        }

        app(Assignments::class)->submit($assignment, $assignee, ['count' => '12', 'notes' => 'shelf B']);

        $this->assertSame(['count' => 12, 'notes' => 'shelf B'], $this->stepFor($execution, 'check')->output['values']);
        $this->assertSame('12 left', $this->stepFor($execution, 'after')->output['value']);
    }
}
