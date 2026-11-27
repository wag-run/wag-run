<?php

namespace App\Engine;

use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Models\Execution;
use App\Models\ExecutionStep;
use Closure;

/**
 * Reads what a finished loop produced from the run's step rows, so the
 * loop's own row only holds how many iterations it ran.
 *
 *     results[n].<id>.output  what that node produced in iteration n
 *     results[n].<id>.port    the output it left by
 *
 * Every node that completed in an iteration is there, in the same shape as
 * nodes.<id>. A loop inside an iteration carries its own results.
 */
class LoopResults
{
    /**
     * Completed steps, oldest first, by scope key.
     *
     * @var array<string, list<ExecutionStep>>|null
     */
    protected ?array $byScope = null;

    /**
     * @param  iterable<ExecutionStep>|Closure(): iterable<ExecutionStep>  $steps  the run's steps, oldest first; steps that did not complete are ignored
     */
    public function __construct(protected iterable|Closure $steps) {}

    /**
     * Loads the run's steps only when a loop's results are asked for.
     */
    public static function for(Execution $execution): self
    {
        return new self(fn () => $execution->steps()
            ->where('status', StepStatus::Complete)
            ->orderBy('id')
            ->get(['id', 'node_id', 'scope_key', 'status', 'wait_type', 'output', 'output_ports']));
    }

    /**
     * A step as other nodes read it.
     *
     * @return array{output: mixed, port: string|null}
     */
    public function entry(ExecutionStep $step): array
    {
        return ['output' => $this->outputOf($step), 'port' => $step->output_ports[0] ?? null];
    }

    /**
     * A step's output, with results added when it is a finished loop.
     */
    public function outputOf(ExecutionStep $step): mixed
    {
        if (! $this->isFinishedLoop($step)) {
            return $step->output;
        }

        $results = [];

        for ($index = 0; $index < (int) $step->output['count']; $index++) {
            $results[] = $this->nodesIn(Scope::forIteration($step->scope_key, $step->id, $index));
        }

        return ['results' => $results, ...$step->output];
    }

    /**
     * Every node that completed in one scope, by node id. A node that ran
     * more than once there is read from its latest step.
     *
     * @return array<string, array{output: mixed, port: string|null}>
     */
    public function nodesIn(string $scope): array
    {
        $nodes = [];

        foreach ($this->byScope()[$scope] ?? [] as $step) {
            $nodes[$step->node_id] = $this->entry($step);
        }

        return $nodes;
    }

    protected function isFinishedLoop(ExecutionStep $step): bool
    {
        return $step->status === StepStatus::Complete
            && $step->wait_type === WaitType::Loop
            && is_array($step->output)
            && isset($step->output['count']);
    }

    /**
     * @return array<string, list<ExecutionStep>>
     */
    protected function byScope(): array
    {
        if ($this->byScope !== null) {
            return $this->byScope;
        }

        $this->byScope = [];

        foreach ($this->steps instanceof Closure ? ($this->steps)() : $this->steps as $step) {
            if ($step->status === StepStatus::Complete) {
                $this->byScope[$step->scope_key][] = $step;
            }
        }

        return $this->byScope;
    }
}
