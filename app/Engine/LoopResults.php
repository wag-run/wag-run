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
 *     last                    the final iteration, like one entry of
 *                             results, or null when the loop never ran
 *
 * Every node that completed in an iteration is there, in the same shape as
 * nodes.<id>. A loop inside an iteration carries its own results.
 *
 * A loop's row holds how many times its body ran as "count": a For
 * loop's iterations, and the passes of a loop that repeats, such as a
 * While loop.
 *
 * The steps come from a list, from the whole run (for), or from the
 * scopes that are asked for (loadedBy), which a step of a run in flight
 * uses so that it never reads the rest of the run. Loaded by scope, a
 * loop's results and last are values that load when an expression reads
 * them (IterationList, IterationNodes), so a step that reads neither
 * loads nothing for the loop.
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
     * Loads the completed steps under a scope, when steps are loaded by
     * scope.
     *
     * @var (Closure(string, string|null): iterable<ExecutionStep>)|null
     */
    protected ?Closure $stepsUnder = null;

    /**
     * The scope prefixes whose steps are loaded.
     *
     * @var list<string>
     */
    protected array $loaded = [];

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
     * Loads only the steps a loop's results or one iteration need, when
     * they are asked for. outputOf() then gives a loop's results and last
     * as values that load when they are read.
     *
     * @param  Closure(string $prefix, string|null $scope): iterable<ExecutionStep>  $stepsUnder  the run's completed steps, oldest first, in every scope that starts with $prefix and, when given, in $scope itself
     */
    public static function loadedBy(Closure $stepsUnder): self
    {
        $results = new self([]);
        $results->stepsUnder = $stepsUnder;

        return $results;
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
     * A step's output, with results and last added when it is a finished
     * loop.
     */
    public function outputOf(ExecutionStep $step): mixed
    {
        $count = $this->iterations($step);

        if ($count === null) {
            return $step->output;
        }

        if ($this->stepsUnder !== null && $count > 0) {
            return [
                'results' => new IterationList($count, fn (int $index) => $this->iteration($step, $index), fn () => $this->everyIteration($step, $count)),
                ...$step->output,
                'last' => new IterationNodes(fn () => $this->iteration($step, $count - 1)),
            ];
        }

        $results = $this->everyIteration($step, $count);

        return ['results' => $results, ...$step->output, 'last' => $results === [] ? null : $results[count($results) - 1]];
    }

    /**
     * The nodes of every iteration of a loop, in order, read with one
     * load.
     *
     * @return list<array<string, array{output: mixed, port: string|null}>>
     */
    protected function everyIteration(ExecutionStep $loop, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $this->load(Scope::iterationsOf($loop->scope_key, $loop->id));

        $results = [];

        for ($index = 0; $index < $count; $index++) {
            $results[] = $this->iteration($loop, $index);
        }

        return $results;
    }

    /**
     * The nodes of one iteration of a loop.
     *
     * @return array<string, array{output: mixed, port: string|null}>
     */
    protected function iteration(ExecutionStep $loop, int $index): array
    {
        return $this->nodesIn(Scope::forIteration($loop->scope_key, $loop->id, $index));
    }

    /**
     * Every node that completed in one scope, by node id. A node that ran
     * more than once there is read from its latest step.
     *
     * @return array<string, array{output: mixed, port: string|null}>
     */
    public function nodesIn(string $scope): array
    {
        $this->load($scope.'/', $scope);

        $nodes = [];

        foreach ($this->byScope()[$scope] ?? [] as $step) {
            $nodes[$step->node_id] = $this->entry($step);
        }

        return $nodes;
    }

    /**
     * How many iterations a finished loop ran, or null when the step is
     * not one.
     */
    protected function iterations(ExecutionStep $step): ?int
    {
        if ($step->status !== StepStatus::Complete || $step->wait_type !== WaitType::Loop || ! is_array($step->output)) {
            return null;
        }

        $count = $step->output['count'] ?? null;

        return is_numeric($count) ? (int) $count : null;
    }

    /**
     * Has the steps in every scope that starts with $prefix, and in $scope
     * itself when given, ready to read. Does nothing unless steps are
     * loaded by scope, or when an earlier load already brought them.
     */
    protected function load(string $prefix, ?string $scope = null): void
    {
        if ($this->stepsUnder === null || array_any($this->loaded, fn (string $done) => str_starts_with($prefix, $done))) {
            return;
        }

        $this->loaded[] = $prefix;
        $found = [];

        foreach (($this->stepsUnder)($prefix, $scope) as $step) {
            if ($step->status === StepStatus::Complete) {
                $found[$step->scope_key][] = $step;
            }
        }

        $this->byScope = [...$this->byScope ?? [], ...$found];
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
