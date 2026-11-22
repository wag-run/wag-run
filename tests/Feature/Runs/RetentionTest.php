<?php

namespace Tests\Feature\Runs;

use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    private function workflowIn(Workspace $workspace, ?int $retentionDays = null): Workflow
    {
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'start', 'say' => 'emit'], ['start->say']), $workspace);
        $workflow->update(['retention_days' => $retentionDays]);

        return $workflow;
    }

    private function runFinishedDaysAgo(Workflow $workflow, int $days, ExecutionStatus $status = ExecutionStatus::Complete): Execution
    {
        $execution = $this->runWorkflow($workflow);
        $execution->forceFill(['status' => $status, 'finished_at' => now()->subDays($days)->subMinute()])->save();

        return $execution;
    }

    public function test_finished_runs_past_retention_are_removed_with_their_steps_whatever_their_status(): void
    {
        $workspace = Workspace::factory()->create(['retention_days' => 7]);
        $workflow = $this->workflowIn($workspace);

        $oldComplete = $this->runFinishedDaysAgo($workflow, 8);
        $oldFailed = $this->runFinishedDaysAgo($workflow, 8, ExecutionStatus::Failed);
        $recent = $this->runFinishedDaysAgo($workflow, 6);

        $this->artisan('workflows:purge-executions')->assertSuccessful();

        $this->assertModelMissing($oldComplete);
        $this->assertModelMissing($oldFailed);
        $this->assertModelExists($recent);
        $this->assertDatabaseMissing('execution_steps', ['execution_id' => $oldComplete->id]);
    }

    public function test_a_workflow_override_wins_over_the_workspace_and_organization_defaults(): void
    {
        Organization::factory()->create(['default_retention_days' => 30]);
        $workspace = Workspace::factory()->create();

        $usesDefault = $this->workflowIn($workspace);
        $overridden = $this->workflowIn($workspace, 2);

        $keptByDefault = $this->runFinishedDaysAgo($usesDefault, 10);
        $removedByOverride = $this->runFinishedDaysAgo($overridden, 3);

        $this->artisan('workflows:purge-executions')->assertSuccessful();

        $this->assertModelExists($keptByDefault);
        $this->assertModelMissing($removedByOverride);
    }

    public function test_runs_in_progress_and_runs_kept_forever_are_never_removed(): void
    {
        Organization::factory()->create(['default_retention_days' => null]);
        $foreverWorkspace = Workspace::factory()->create();
        $kept = $this->runFinishedDaysAgo($this->workflowIn($foreverWorkspace), 900);

        $inProgress = $this->runWorkflow($this->workflowIn(Workspace::factory()->create(['retention_days' => 1])));
        $inProgress->forceFill(['status' => ExecutionStatus::Waiting, 'finished_at' => null, 'started_at' => now()->subDays(5)])->save();

        $this->artisan('workflows:purge-executions')->assertSuccessful();

        $this->assertModelExists($kept);
        $this->assertModelExists($inProgress);
    }
}
