<?php

namespace App\Models;

use App\Engine\Graph\WorkflowGraph;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable published graph. Every execution is pinned to one.
 *
 * @property int $id
 * @property string $workflow_id
 * @property int $number
 * @property array<string, mixed> $graph
 * @property int|null $published_by
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Workflow $workflow
 * @property-read User|null $publisher
 */
#[Fillable(['workflow_id', 'number', 'graph', 'published_by', 'note'])]
class WorkflowVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'graph' => 'array',
        ];
    }

    public function toGraph(): WorkflowGraph
    {
        return WorkflowGraph::fromArray($this->graph);
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
