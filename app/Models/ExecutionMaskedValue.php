<?php

namespace App\Models;

use App\Enums\MaskedSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The REAL values behind the masks in one part of a run in flight.
 *
 * Despite the table's name, these are the secrets themselves: the token a
 * step received, a password in a webhook body, a cookie. Everywhere else
 * (the step and execution rows, the run page, the API, MCP) they are
 * replaced by Redactor::MASK. The engine reads them back only while
 * building what a later step of the same run can see (ContextBuilder),
 * and deletes them as soon as the run finishes, is cancelled, or is
 * skipped (MaskedValues::forget). Never show or return them.
 *
 * One row per step and slot; trigger and variable rows have no step.
 * Each value's path is a list of keys and positions from the slot's top,
 * such as ["body", "items", 3, "secret"], since a key may hold a dot.
 *
 * @property int $id
 * @property string $execution_id
 * @property int|null $execution_step_id
 * @property MaskedSlot $slot
 * @property list<array{path: list<string|int>, value: mixed}>|null $values
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['execution_id', 'execution_step_id', 'slot', 'values'])]
class ExecutionMaskedValue extends Model
{
    protected function casts(): array
    {
        return [
            'slot' => MaskedSlot::class,
            'values' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
