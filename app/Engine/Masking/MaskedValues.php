<?php

namespace App\Engine\Masking;

use App\Enums\MaskedSlot;
use App\Models\Execution;
use App\Models\ExecutionMaskedValue;
use App\Models\ExecutionStep;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the real values behind a run's masks while the run is in flight,
 * so later steps can still use a token an earlier one received, and puts
 * them back into the data a step reads. The rows go as soon as the run
 * ends (forget), so a finished run holds masks only.
 *
 * Only the engine reads them back, in memory, when it builds what a step
 * can see. Everything that shows a run (the run page, the API, MCP, the
 * editor's preview) reads the masked rows and never comes here.
 */
class MaskedValues
{
    /**
     * Keep the real values a slot's masks replaced, in place of any kept
     * before. Nothing is kept when nothing was masked.
     *
     * @param  list<array{path: list<string|int>, value: mixed}>  $values
     */
    public function keep(string $executionId, ?int $stepId, MaskedSlot $slot, array $values): void
    {
        $existing = $this->query($executionId, $stepId, $slot)->first();

        if ($values === []) {
            $existing?->delete();

            return;
        }

        if ($existing) {
            $existing->forceFill(['values' => $values])->save();

            return;
        }

        ExecutionMaskedValue::query()->create([
            'execution_id' => $executionId,
            'execution_step_id' => $stepId,
            'slot' => $slot,
            'values' => $values,
        ]);
    }

    /**
     * Keep one workflow variable's real values, leaving the others'.
     *
     * @param  list<array{path: list<string|int>, value: mixed}>  $values  paths start with the variable's name
     */
    public function keepVariable(string $executionId, string $name, array $values): void
    {
        $others = array_values(array_filter(
            $this->query($executionId, null, MaskedSlot::Variable)->first()->values ?? [],
            fn (array $entry) => ($entry['path'][0] ?? null) !== $name,
        ));

        $this->keep($executionId, null, MaskedSlot::Variable, [...$others, ...$values]);
    }

    /**
     * Give a retried step the state values of the attempt it retries.
     */
    public function copyState(ExecutionStep $from, ExecutionStep $to): void
    {
        $values = $this->query($from->execution_id, $from->id, MaskedSlot::State)->first()->values ?? [];

        $this->keep($to->execution_id, $to->id, MaskedSlot::State, $values);
    }

    /**
     * Everything kept for a run, to put back with restore().
     */
    public function of(Execution|string $execution): RunMaskedValues
    {
        $executionId = $execution instanceof Execution ? $execution->id : $execution;

        return new RunMaskedValues(ExecutionMaskedValue::query()->where('execution_id', $executionId)->get());
    }

    /**
     * Drop everything kept for a run. Call it when the run reaches its end.
     */
    public function forget(Execution $execution): void
    {
        ExecutionMaskedValue::query()->where('execution_id', $execution->id)->delete();
    }

    /**
     * $data with the real values put back at their paths. A path that no
     * longer leads anywhere is skipped.
     *
     * @param  list<array{path: list<string|int>, value: mixed}>  $values
     */
    public static function restore(mixed $data, array $values): mixed
    {
        foreach ($values as $entry) {
            $data = self::put($data, $entry['path'], $entry['value']);
        }

        return $data;
    }

    /**
     * @param  list<string|int>  $path
     */
    protected static function put(mixed $data, array $path, mixed $value): mixed
    {
        if ($path === []) {
            return $value;
        }

        $key = array_shift($path);

        if (! is_array($data) || ! array_key_exists($key, $data)) {
            return $data;
        }

        $data[$key] = self::put($data[$key], $path, $value);

        return $data;
    }

    /**
     * @return Builder<ExecutionMaskedValue>
     */
    protected function query(string $executionId, ?int $stepId, MaskedSlot $slot): Builder
    {
        return ExecutionMaskedValue::query()
            ->where('execution_id', $executionId)
            ->where('execution_step_id', $stepId)
            ->where('slot', $slot);
    }
}
