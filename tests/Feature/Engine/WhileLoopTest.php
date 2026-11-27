<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\LoopResults;
use App\Engine\StepRunner;
use App\Enums\ExecutionStatus;
use App\Enums\ResumeReason;
use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Jobs\ResumeStep;
use App\Models\Execution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class WhileLoopTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_a_pass_that_waits_keeps_the_loop_waiting_until_it_finishes(): void
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'start', 'poll' => ['core.while_loop', ['condition' => '{{ nodes.tick.output.value < 2 }}', 'check_after' => true]],
                'wait' => ['pause', ['minutes' => 5]], 'tick' => ['core.compose', ['template' => '{{ loop.index + 1 }}']]],
            ['start->poll', 'poll:pass->wait', 'wait->tick'],
        )));
        $loop = $this->stepFor($execution, 'poll');

        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertSame([StepStatus::Waiting, WaitType::Loop, null], [$loop->status, $loop->wait_type, $loop->resume_at]);

        $this->travel(6)->minutes();
        $this->sweep();

        $this->assertSame(ExecutionStatus::Waiting, $execution->refresh()->status);
        $this->assertSame(1, $loop->refresh()->state['index']);
        $this->assertSame(2, $execution->steps()->where('node_id', 'wait')->count());

        $this->travel(6)->minutes();
        $this->sweep();

        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
        $this->assertSame(['count' => 2], $loop->refresh()->output);
        $this->assertSame(2, $execution->output['poll']['output']['last']['tick']['output']['value']);
    }

    public function test_a_failure_inside_a_pass_fails_the_run_unless_its_error_output_catches_it(): void
    {
        $graph = fn (array $edges) => $this->graph(
            ['start' => 'start', 'spin' => ['core.while_loop', ['condition' => '{{ loop.index < 1 }}', 'check_after' => true]],
                'call' => ['flaky', ['permanent' => true]], 'caught' => 'emit'],
            ['start->spin', 'spin:pass->call', ...$edges],
        );

        $failed = $this->runWorkflow($this->publishedWorkflow($graph([])));

        $this->assertSame(ExecutionStatus::Failed, $failed->status);
        $this->assertSame('call', $failed->error['node_id']);
        $this->assertSame(StepStatus::Cancelled, $this->stepFor($failed, 'spin')->status);
        $this->assertSame(1, $failed->steps()->where('node_id', 'call')->count());

        $caught = $this->runWorkflow($this->publishedWorkflow($graph(['call:error->caught'])));

        $this->assertSame(ExecutionStatus::Complete, $caught->status);
        $this->assertSame(2, $caught->steps()->where('node_id', 'caught')->count());
        $this->assertSame(['count' => 2], $this->stepFor($caught, 'spin')->output);
    }

    public function test_cancelling_a_run_cancels_its_loop_and_a_late_check_changes_nothing(): void
    {
        $execution = $this->waitingInsideAPass();
        $loop = $this->stepFor($execution, 'poll');

        // The pass finishes and its check is queued, but the run is cancelled before a worker runs it.
        Queue::fake([ResumeStep::class]);
        $this->travel(6)->minutes();
        app(StepRunner::class)->resume($this->stepFor($execution, 'wait')->id, ResumeReason::TimerElapsed);
        $this->assertNotNull($loop->refresh()->resume_at);

        app(Engine::class)->cancel($execution);
        app(StepRunner::class)->resume($loop->id, ResumeReason::PassFinished);

        $this->assertSame(ExecutionStatus::Cancelled, $execution->refresh()->status);
        $this->assertSame(StepStatus::Cancelled, $loop->refresh()->status);
        $this->assertSame(1, $execution->steps()->where('node_id', 'wait')->count());
    }

    public function test_a_finished_pass_is_looked_at_once_and_the_sweep_queues_a_lost_check_again(): void
    {
        $execution = $this->waitingInsideAPass();
        $loop = $this->stepFor($execution, 'poll');

        Queue::fake([ResumeStep::class]);
        app(StepRunner::class)->resume($this->stepFor($execution, 'wait')->id, ResumeReason::TimerElapsed);
        $this->assertNull($loop->refresh()->resume_at);

        $this->travel(6)->minutes();
        app(StepRunner::class)->resume($this->stepFor($execution, 'wait')->id, ResumeReason::TimerElapsed);

        $this->assertNotNull($loop->refresh()->resume_at);
        $this->assertSame(StepStatus::Waiting, $loop->status);
        Queue::assertPushed(ResumeStep::class, fn (ResumeStep $job) => $job->stepId === $loop->id && $job->reason === ResumeReason::PassFinished);
        Queue::assertPushed(ResumeStep::class, 1);

        $this->sweep();
        Queue::assertPushed(ResumeStep::class, 1);

        $this->travel(61)->seconds();
        $this->sweep();
        Queue::assertPushed(ResumeStep::class, 2);

        app(StepRunner::class)->resume($loop->id, ResumeReason::PassFinished);
        app(StepRunner::class)->resume($loop->id, ResumeReason::PassFinished);

        $this->assertSame(1, $loop->refresh()->state['index']);
        $this->assertSame(StepStatus::Waiting, $loop->status, 'the second pass is still running');
        $this->assertNull($loop->resume_at);
        $this->assertSame(2, $execution->steps()->where('node_id', 'wait')->count());
    }

    public function test_a_while_loop_inside_a_while_loop_keeps_its_own_passes(): void
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'start', 'outer' => ['core.while_loop', ['condition' => '{{ loop.index < 1 }}', 'check_after' => true]],
                'inner' => ['core.while_loop', ['condition' => '{{ nodes.tick.output.value < 2 }}', 'check_after' => true]],
                'tick' => ['core.compose', ['template' => '{{ loop.index + 1 }}']],
                'tail' => ['core.compose', ['template' => '{{ loop.index }}:{{ nodes.inner.output.count }}:{{ loop.previous.inner.output.count ?? "none" }}']]],
            ['start->outer', 'outer:pass->inner', 'inner:pass->tick', 'inner:done->tail'],
        )));

        $outer = LoopResults::for($execution)->outputOf($this->stepFor($execution, 'outer'));

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame([1, 2, 1, 2], $execution->steps()->where('node_id', 'tick')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame(['0:2:none', '1:2:2'], $execution->steps()->where('node_id', 'tail')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame(2, $outer['count']);
        $this->assertSame(2, $outer['results'][1]['inner']['output']['last']['tick']['output']['value']);
        $this->assertSame($outer['results'][1], $outer['last']);
    }

    public function test_the_check_before_the_first_pass_reads_its_own_empty_loop_and_the_for_loops_item(): void
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['core.for_loop', ['items' => '{{ [10, 20] }}']],
                'poll' => ['core.while_loop', ['condition' => '{{ loop.index === null and loop.previous === null }}']],
                'body' => ['core.compose', ['template' => '{{ item }}:{{ loop.index }}']]],
            ['start->each', 'each:item->poll', 'poll:pass->body'],
        )));

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['10:0', '20:0'], $execution->steps()->where('node_id', 'body')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame([['count' => 1], ['count' => 1]], $execution->steps()->where('node_id', 'poll')->orderBy('id')->get()->map(fn ($step) => $step->output)->all());
    }

    public function test_a_node_that_repeats_without_its_own_check_stops_after_one_pass(): void
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'start', 'once' => 'again', 'body' => 'emit', 'after' => 'emit'],
            ['start->once', 'once:pass->body', 'once:done->after'],
        )));

        $this->assertSame(['start', 'once', 'body', 'after'], $this->nodesRun($execution));
        $this->assertSame(['count' => 1], $this->stepFor($execution, 'once')->output);
    }

    /**
     * A run parked on a five-minute wait inside the first pass of a While
     * loop that goes on for two passes.
     */
    protected function waitingInsideAPass(): Execution
    {
        return $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'start', 'poll' => ['core.while_loop', ['condition' => '{{ loop.index < 1 }}', 'check_after' => true]], 'wait' => ['pause', ['minutes' => 5]]],
            ['start->poll', 'poll:pass->wait'],
        )));
    }
}
