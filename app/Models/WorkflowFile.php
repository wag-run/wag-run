<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a run made or received. Its bytes are at path on disk; nodes refer
 * to it with the small reference WorkflowFiles::reference() gives.
 *
 * @property string $id
 * @property int $workspace_id
 * @property string|null $execution_id
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $disk
 * @property string $path
 */
#[Fillable(['workspace_id', 'execution_id', 'name', 'mime', 'size', 'sha256', 'disk', 'path'])]
class WorkflowFile extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
