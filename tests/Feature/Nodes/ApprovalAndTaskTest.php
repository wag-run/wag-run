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
use App\Services\Assignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'approve' => ['core.approval', ['assignee' => $approver->id, 'title' => 'Refund {{ trigger.amount }}', 'message' => 'For {{ trigger.customer }}']],
                'refund' => ['core.compose', ['template' => 'refunded']],
                'decline' => ['core.compose', ['template' => 'declined']],
                'escalate' => ['core.compose', ['template' => '{{ error.message }}']]],
            ['start->approve', 'approve:approved->refund', 'approve:rejected->decline', 'approve:error->escalate'],
        ), $workspace);
    }

    public function test_approval_waits_for_the_named_user_and_follows_their_decision(): void
    {
        $this->freezeSecond();
        $approver = User::factory()->installMember()->create();
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
        $this->assertSame(['decision' => 'approved', 'comment' => 'Looks fine', 'responded_by' => $approver->id, 'responded_by_name' => $approver->name], $this->stepFor($execution, 'approve')->output);
        $this->assertSame(AssignmentStatus::Approved, $assignment->refresh()->status);
    }

    public function test_rejection_follows_the_rejected_path(): void
    {
        $approver = User::factory()->installMember()->create();
        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        app(Assignments::class)->reject(Assignment::sole(), $approver);

        $this->assertSame(['start', 'approve', 'decline'], $this->nodesRun($execution));
    }

    public function test_assignee_outside_the_workspace_joins_as_a_guest(): void
    {
        $workspace = Workspace::factory()->create();
        $outsider = User::factory()->installMember()->create();

        $this->runWorkflow($this->approvalWorkflow($outsider, $workspace));

        $this->assertSame(WorkspaceRole::Guest, $outsider->roleIn($workspace));
    }

    public function test_only_the_assignee_can_answer_and_only_once(): void
    {
        $approver = User::factory()->installMember()->create();
        $someoneElse = User::factory()->installMember()->create();
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
        $approver = User::factory()->installMember()->create();
        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        $this->travel(49)->hours();
        $this->sweep();

        $this->assertSame(AssignmentStatus::Expired, Assignment::sole()->status);
        $this->assertSame('No response before the approval timed out.', $this->stepFor($execution, 'escalate')->output['value']);
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_cancelling_the_run_withdraws_open_assignments(): void
    {
        $approver = User::factory()->installMember()->create();
        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        app(Engine::class)->cancel($execution);

        $this->assertSame(AssignmentStatus::Cancelled, Assignment::sole()->status);
    }

    public function test_deactivated_assignee_fails_the_step(): void
    {
        User::factory()->installAdmin()->create();
        $approver = User::factory()->deactivated()->create();

        $execution = $this->runWorkflow($this->approvalWorkflow($approver));

        $this->assertSame('The approver does not have an active account on this install.', $this->stepFor($execution, 'escalate')->output['value']);
        $this->assertSame(0, Assignment::count());
    }

    public function test_task_validates_the_form_and_returns_the_submitted_values(): void
    {
        $assignee = User::factory()->installMember()->create();
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

        $this->assertSame(['count' => '12', 'notes' => 'shelf B'], $this->stepFor($execution, 'check')->output['values']);
        $this->assertSame('12 left', $this->stepFor($execution, 'after')->output['value']);
    }
}
