<?php

namespace App\Engine\Masking;

use App\Enums\MaskedSlot;
use App\Models\ExecutionMaskedValue;
use Illuminate\Support\Collection;

/**
 * The real values kept for one run, loaded once while a step's data is
 * built, to put back into the trigger data, step outputs, loop state and
 * variables that step reads.
 */
final class RunMaskedValues
{
    /**
     * @var array<string, list<array{path: list<string|int>, value: mixed}>>
     */
    private array $bySlot = [];

    /**
     * @param  Collection<int, ExecutionMaskedValue>  $rows
     */
    public function __construct(Collection $rows)
    {
        foreach ($rows as $row) {
            $this->bySlot[$this->key($row->execution_step_id, $row->slot)] = $row->values ?? [];
        }
    }

    /**
     * $data with the slot's real values put back.
     */
    public function restore(mixed $data, MaskedSlot $slot, ?int $stepId = null): mixed
    {
        $values = $this->bySlot[$this->key($stepId, $slot)] ?? [];

        return $values === [] ? $data : MaskedValues::restore($data, $values);
    }

    private function key(?int $stepId, MaskedSlot $slot): string
    {
        return ($stepId ?? '-').':'.$slot->value;
    }
}
