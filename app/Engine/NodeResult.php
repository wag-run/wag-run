<?php

namespace App\Engine;

use App\Enums\ExecutionStatus;
use App\Enums\ValueOperation;
use App\Models\Workflow;
use Carbon\CarbonInterface;

/**
 * What a node returns from execute() or resume(). The engine turns it into
 * step status changes and the next step rows.
 */
final readonly class NodeResult
{
    /**
     * @param  list<string>  $ports
     * @param  array<string, mixed>  $state
     * @param  list<mixed>  $items
     * @param  array<string, mixed>  $childInput
     */
    private function __construct(
        public ResultKind $kind,
        public mixed $output = null,
        public array $ports = ['main'],
        public ?string $error = null,
        public bool $retryable = true,
        public ?CarbonInterface $until = null,
        public bool $external = false,
        public array $state = [],
        public ?ExecutionStatus $terminateAs = null,
        public array $items = [],
        public string $bodyPort = 'item',
        public string $donePort = 'done',
        public ?Workflow $childWorkflow = null,
        public array $childInput = [],
        public ?string $variable = null,
        public ?ValueOperation $operation = null,
        public mixed $operand = null,
    ) {}

    /**
     * Finish successfully and continue down the given output ports. Passing
     * several ports fans out to parallel branches.
     *
     * @param  string|list<string>  $ports
     */
    public static function complete(mixed $output = null, string|array $ports = 'main'): self
    {
        return new self(ResultKind::Complete, output: $output, ports: (array) $ports);
    }

    /**
     * Fail this attempt. Retryable failures use the step's retry policy.
     */
    public static function fail(string $message, bool $retryable = true, mixed $output = null): self
    {
        return new self(ResultKind::Fail, output: $output, error: $message, retryable: $retryable);
    }

    /**
     * Park the step until a time passes. The sweep resumes it with TimerElapsed.
     *
     * @param  array<string, mixed>  $state
     */
    public static function waitUntil(CarbonInterface $until, array $state = []): self
    {
        return new self(ResultKind::Wait, until: $until, state: $state);
    }

    /**
     * Park the step until something outside responds, such as an approval.
     * An optional timeout resumes it with TimerElapsed instead.
     *
     * @param  array<string, mixed>  $state
     */
    public static function waitForResponse(?CarbonInterface $timeout = null, array $state = []): self
    {
        return new self(ResultKind::Wait, until: $timeout, external: true, state: $state);
    }

    /**
     * End the whole execution with a status. Any other open step is cancelled.
     */
    public static function terminate(ExecutionStatus $status, mixed $output = null): self
    {
        return new self(ResultKind::Terminate, output: $output, terminateAs: $status);
    }

    /**
     * Run the part of the flow on $bodyPort once per item, one item at a time,
     * then continue down $donePort with every iteration's results, their
     * count, and the last iteration.
     *
     * @param  array<int|string, mixed>  $items
     */
    public static function iterate(array $items, string $bodyPort = 'item', string $donePort = 'done'): self
    {
        return new self(ResultKind::Iterate, items: array_values($items), bodyPort: $bodyPort, donePort: $donePort);
    }

    /**
     * Run the part of the flow on $bodyPort once. When that pass has
     * finished, the node's resume() is called with PassFinished, seeing the
     * run's data as a step inside the pass does, and returns repeat() again
     * for another pass or complete() to stop. The engine adds every pass's
     * results and the last pass to an output that holds their count as "count".
     */
    public static function repeat(string $bodyPort = 'pass', string $donePort = 'done'): self
    {
        return new self(ResultKind::Repeat, bodyPort: $bodyPort, donePort: $donePort);
    }

    /**
     * Start another workflow and wait for it. Its output becomes this step's
     * output; if it fails, this step fails.
     *
     * @param  array<string, mixed>  $input
     */
    public static function runChild(Workflow $workflow, array $input = []): self
    {
        return new self(ResultKind::RunChild, childWorkflow: $workflow, childInput: $input);
    }

    /**
     * Change one of the run's workflow variables and finish. The engine
     * applies the change while it holds the run's lock and saves it with
     * the step, whose output is the variable's name, the operation, and
     * the value before and after. A value that doesn't fit fails the step.
     *
     * @param  string|list<string>  $ports
     */
    public static function updateVariable(string $name, ValueOperation $operation, mixed $operand = null, string|array $ports = 'main'): self
    {
        return new self(ResultKind::UpdateVariable, ports: (array) $ports, variable: $name, operation: $operation, operand: $operand);
    }
}
