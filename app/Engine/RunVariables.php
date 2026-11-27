<?php

namespace App\Engine;

use App\Engine\Graph\WorkflowGraph;
use App\Engine\Templates\TemplateEngine;
use App\Enums\ValueOperation;
use App\Enums\ValueType;
use App\Models\Execution;
use App\Models\ExecutionVariable;
use RuntimeException;

/**
 * A run's workflow variables: typed values the workflow declares, which
 * last for that one run. Each starts from its starting value when the run
 * starts and is changed only by the engine while it holds the run's row
 * lock, so changes from parallel branches can't be lost and a change is
 * saved together with the step that made it.
 *
 * Values are masked before they are saved, like step outputs, so a
 * variable can't carry a secret.
 */
class RunVariables
{
    /**
     * What a starting value's placeholders may read. Node outputs, loop
     * items, and other variables don't exist yet when a run starts, and a
     * secret would be saved masked.
     */
    public const STARTING_VALUE_ROOTS = ['trigger', 'store', 'execution'];

    public const STARTING_VALUE_MAX_LENGTH = 10000;

    public function __construct(
        protected TemplateEngine $templates,
        protected ContextBuilder $contexts,
    ) {}

    /**
     * Work out every declared variable's starting value and save it. An
     * empty starting value, or one that renders to nothing, is the type's
     * empty value.
     *
     * @throws RuntimeException naming the variable, when a starting value can't be worked out
     */
    public function start(Execution $execution, WorkflowGraph $graph, Redactor $redactor): void
    {
        if ($graph->variables === []) {
            return;
        }

        $data = $this->contexts->atStart($execution);
        $rows = [];

        foreach ($graph->variables as $variable) {
            try {
                $type = $variable->valueType() ?? throw new RuntimeException(__('engine.variables.unknown_type', ['type' => $variable->type]));
                $rendered = $this->templates->render($variable->value, $data);
                $value = $rendered === null || ($rendered === '' && $type !== ValueType::String) ? $type->emptyValue() : $type->convert($rendered);

                $rows[] = [
                    'execution_id' => $execution->id,
                    'name' => $variable->name,
                    'value' => $this->encode($type, $redactor->mask($value)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } catch (RuntimeException $e) {
                throw new RuntimeException(__('engine.variables.could_not_start', ['name' => $variable->name, 'message' => $e->getMessage()]), previous: $e);
            }
        }

        ExecutionVariable::query()->insert($rows);
    }

    /**
     * Change a variable. Call it only while holding the run's row lock.
     *
     * @return array{before: mixed, after: mixed}
     *
     * @throws RuntimeException when the variable isn't declared, the operation or value doesn't fit its type, or the result is too large
     */
    public function apply(Execution $execution, WorkflowGraph $graph, string $name, ValueOperation $operation, mixed $operand, Redactor $redactor): array
    {
        $type = ($graph->variables[$name] ?? null)?->valueType();

        if ($type === null) {
            throw new RuntimeException(__('engine.variables.not_declared', ['name' => $name]));
        }

        $variable = $execution->variables()->where('name', $name)->lockForUpdate()->first();
        $before = $variable?->decoded();
        $after = $redactor->mask($operation->apply($type, $before, $operand));
        $json = $this->encode($type, $after);

        if ($variable) {
            $variable->forceFill(['value' => $json])->save();
        } else {
            $execution->variables()->create(['name' => $name, 'value' => $json]);
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * @return array<string, mixed> the run's current values by variable name
     */
    public function all(Execution $execution): array
    {
        return $execution->variables()->orderBy('id')->pluck('value', 'name')
            ->map(fn (string $json) => json_decode($json, true))->all();
    }

    /**
     * @throws RuntimeException when the value can't be saved as JSON or is too large
     */
    protected function encode(ValueType $type, mixed $value): string
    {
        return $type->encode($value, (int) config('workflows.variables.max_value_bytes'));
    }
}
