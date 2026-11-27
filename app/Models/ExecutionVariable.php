<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current value of one workflow variable in one run. Rows are created
 * when the run starts and changed only by the engine, under the run's row
 * lock (see RunVariables).
 *
 * @property int $id
 * @property string $execution_id
 * @property string $name
 * @property string $value JSON
 */
#[Fillable(['execution_id', 'name', 'value'])]
class ExecutionVariable extends Model
{
    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function decoded(): mixed
    {
        return json_decode($this->value, true);
    }
}
