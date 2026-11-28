<?php

namespace App\Engine;

use App\Engine\Graph\GraphVariable;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\Masking\MaskedValues;
use App\Engine\Templates\TemplateEngine;
use App\Enums\MaskedSlot;
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
 * Values are masked before they are saved, like step outputs. The real
 * values are kept aside while the run is in flight (MaskedValues), so a
 * token saved to a variable can still be read by a later step of the same
 * run, and are gone once it ends.
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
        protected MaskedValues $masked,
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
        $realValues = [];

        foreach ($graph->variables as $variable) {
            try {
                $worked = $this->workOut($variable, $data, $redactor);
                array_push($realValues, ...$worked['real']);
                $rows[] = [
                    'execution_id' => $execution->id,
                    'name' => $variable->name,
                    'value' => $worked['json'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } catch (RuntimeException $e) {
                throw new RuntimeException(__('engine.variables.could_not_start', ['name' => $variable->name, 'message' => $e->getMessage()]), previous: $e);
            }
        }

        ExecutionVariable::query()->insert($rows);
        $this->masked->keep($execution->id, null, MaskedSlot::Variable, $realValues);
    }

    /**
     * What a variable would start a run as, given the data its starting
     * value can read (see ContextBuilder::atStart): converted to its type
     * and masked, as it would be saved.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException when the starting value can't be worked out, converted, or saved
     */
    public function startingValue(GraphVariable $variable, array $data, Redactor $redactor): mixed
    {
        return $this->workOut($variable, $data, $redactor)['value'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{value: mixed, json: string, real: list<array{path: list<string|int>, value: mixed}>}
     *
     * @throws RuntimeException
     */
    protected function workOut(GraphVariable $variable, array $data, Redactor $redactor): array
    {
        $type = $variable->valueType() ?? throw new RuntimeException(__('engine.variables.unknown_type', ['type' => $variable->type]));
        $rendered = $this->templates->render($variable->value, $data);
        [$value, $real] = $this->masking($variable->name, $rendered === null || ($rendered === '' && $type !== ValueType::String) ? $type->emptyValue() : $type->convert($rendered), $redactor);

        return ['value' => $value, 'json' => $this->encode($type, $value), 'real' => $real];
    }

    /**
     * A variable's value masked, and the real values the masks replaced,
     * with paths that start with the variable's name, as forms and names
     * are matched under it (a variable called api_token is masked whole).
     *
     * @return array{0: mixed, 1: list<array{path: list<string|int>, value: mixed}>}
     */
    protected function masking(string $name, mixed $value, Redactor $redactor): array
    {
        [$masked, $real] = $redactor->split([$name => $value]);

        return [$masked[$name], $real];
    }

    /**
     * Change a variable. Call it only while holding the run's row lock.
     * The change starts from the variable's real value, and before and
     * after come back masked, as the step's output shows them.
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
        $realBefore = $this->masked->of($execution)->restore([$name => $before], MaskedSlot::Variable)[$name];
        [$after, $real] = $this->masking($name, $operation->apply($type, $realBefore, $operand), $redactor);
        $json = $this->encode($type, $after);

        if ($variable) {
            $variable->forceFill(['value' => $json])->save();
        } else {
            $execution->variables()->create(['name' => $name, 'value' => $json]);
        }

        $this->masked->keepVariable($execution->id, $name, $real);

        return ['before' => $before, 'after' => $after];
    }

    /**
     * @param  bool  $unmasked  with the real values behind masks, for a step of a run in flight; never for showing a run
     * @return array<string, mixed> the run's current values by variable name
     */
    public function all(Execution $execution, bool $unmasked = false): array
    {
        $values = $execution->variables()->orderBy('id')->pluck('value', 'name')
            ->map(fn (string $json) => json_decode($json, true))->all();

        return $unmasked ? $this->masked->of($execution)->restore($values, MaskedSlot::Variable) : $values;
    }

    /**
     * @throws RuntimeException when the value can't be saved as JSON or is too large
     */
    protected function encode(ValueType $type, mixed $value): string
    {
        return $type->encode($value, (int) config('workflows.variables.max_value_bytes'));
    }
}
