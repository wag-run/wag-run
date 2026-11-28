<?php

namespace Tests\Feature\Engine;

use App\Enums\StepStatus;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ManualRuns;
use App\Services\Workflows;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class DisabledWorkflowTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @param  array<string, mixed>  $graph
     */
    private function publish(array $graph, ?Workspace $workspace = null): Workflow
    {
        $workflow = Workflow::factory()->for($workspace ?? Workspace::factory())->create(['draft_graph' => $graph]);
        app(Workflows::class)->publish($workflow);

        return $workflow->refresh();
    }

    private function disable(Workflow $workflow): void
    {
        $workflow->forceFill(['is_enabled' => false])->save();
    }

    public function test_workflows_start_enabled(): void
    {
        $this->assertTrue(Workflow::factory()->create()->refresh()->is_enabled);
    }

    public function test_a_disabled_workflows_webhook_refuses_requests_without_recording_a_run(): void
    {
        $workflow = $this->publish($this->graph(['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']]]));
        $url = route('webhooks.receive', $workflow->triggers()->sole()->key);
        $this->disable($workflow);

        $this->postJson($url, ['order_id' => 1])->assertConflict()->assertJson(['message' => __('webhooks.workflow_is_disabled')]);
        $this->assertSame(0, Execution::count());

        $workflow->forceFill(['is_enabled' => true])->save();
        $this->postJson($url, ['order_id' => 2])->assertAccepted();
        $this->assertSame(1, Execution::count());
    }

    public function test_a_disabled_workflows_form_says_it_is_not_accepting_responses_and_starts_no_run(): void
    {
        $workflow = $this->publish($this->graph(['signup' => ['core.form_trigger', [
            'title' => 'Join the beta',
            'access' => 'public',
            'fields' => [['name' => 'email', 'label' => 'Email', 'type' => 'email']],
        ]]]));
        $key = $workflow->triggers()->sole()->key;
        $this->disable($workflow);

        $this->get(route('forms.show', $key))->assertOk()
            ->assertSee(__('forms.show.not_accepting_responses'))
            ->assertDontSee('data-test="submit-form-button"', false);

        Livewire::test('pages::forms.show', ['key' => $key])
            ->set('values.email', 'ada@example.com')
            ->call('submit')
            ->assertDontSee(__('forms.show.thanks_your_response_was_submitted'));

        $this->assertSame(0, Execution::count());
    }

    public function test_a_disabled_workflows_schedule_skips_the_times_it_misses_without_catching_up(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:10', 'UTC'));
        $workflow = $this->publish($this->graph(['tick' => ['core.schedule_trigger', ['mode' => 'interval', 'interval_minutes' => 15]]]));
        $trigger = $workflow->triggers()->sole();
        $this->disable($workflow);

        $this->travel(16)->minutes();
        $this->artisan('workflows:run-schedules')->expectsOutputToContain('Started 0 scheduled run(s).');
        $this->assertSame(0, Execution::count());
        $this->assertTrue($trigger->refresh()->next_run_at->isFuture(), 'the missed time is passed over');

        $workflow->forceFill(['is_enabled' => true])->save();
        $this->artisan('workflows:run-schedules');
        $this->assertSame(0, Execution::count(), 'enabling it does not run the time it missed');
    }

    public function test_trigger_other_workflow_fails_when_the_workflow_is_disabled(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']), $workspace);
        $this->disable($child);

        foreach (['sync', 'async'] as $mode) {
            $parent = $this->publishedWorkflow($this->graph(
                ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $child->id, 'mode' => $mode]]],
                ['start->call'],
            ), $workspace);

            $execution = $this->runWorkflow($parent);

            $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'call')->status, $mode);
            $this->assertSame(__('nodes.run_workflow.workflow_name_is_disabled', ['name' => $child->name]), $execution->error['message'], $mode);
        }

        $this->assertSame(0, $child->executions()->count());
    }

    public function test_the_api_shows_a_disabled_workflow_and_refuses_to_start_it(): void
    {
        $workspace = Workspace::factory()->create();
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']), $workspace);
        $this->disable($workflow);
        Passport::actingAs(User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create(), ['workflows:read', 'runs:write']);

        $this->getJson("/api/v1/workspaces/{$workspace->slug}/workflows/{$workflow->id}")->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson("/api/v1/workspaces/{$workspace->slug}/workflows/{$workflow->id}/runs", ['input' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['workflow' => __('workflows.workflow_is_disabled')]);
        $this->assertSame(0, Execution::count());
    }

    public function test_a_disabled_workflow_can_still_be_run_and_test_run_from_the_editor(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $workflow = $this->publish($this->graph(['start' => 'core.manual_trigger']), $workspace);
        $this->disable($workflow);

        $runs = app(ManualRuns::class);
        $this->assertSame('manual', $runs->start($workflow, $user)->trigger_type);
        $this->assertTrue($runs->startTest($workflow, $user)->is_test);
    }
}
