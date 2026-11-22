<?php

namespace App\Models;

use App\Engine\Graph\WorkflowGraph;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable graph every execution is pinned to: a published version, or
 * a snapshot of the draft taken for test runs, which has no number and is
 * never published.
 *
 * @property int $id
 * @property string $workflow_id
 * @property int|null $number null for a draft snapshot
 * @property bool $is_draft a snapshot of the draft for test runs, never published
 * @property array<string, mixed> $graph
 * @property string|null $created_by who published it, or who took the draft snapshot
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Workflow $workflow
 * @property-read User|null $creator
 */
#[Fillable(['workflow_id', 'number', 'is_draft', 'graph', 'created_by', 'note'])]
class WorkflowVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'graph' => 'array',
            'is_draft' => 'boolean',
        ];
    }

    /**
     * "v3" for a published version, "Draft" for a test snapshot.
     */
    public function shortLabel(): string
    {
        return $this->is_draft ? __('runs.draft') : __('common.version_short', ['n' => $this->number]);
    }

    /**
     * "Version 3" for a published version, "Draft" for a test snapshot.
     */
    public function label(): string
    {
        return $this->is_draft ? __('runs.draft') : __('common.version_n', ['n' => $this->number]);
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
