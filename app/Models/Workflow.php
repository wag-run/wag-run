<?php

namespace App\Models;

use App\Engine\Definitions\Field;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\ConcurrencyPolicy;
use App\Enums\ExecutionStatus;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id a UUID
 * @property int $workspace_id
 * @property string|null $owner_id
 * @property string $name
 * @property string|null $description
 * @property ConcurrencyPolicy $concurrency
 * @property int|null $retention_days
 * @property int|null $max_runs_per_minute overrides the organization's default when set
 * @property array<string, mixed> $draft_graph
 * @property Carbon|null $draft_updated_at
 * @property int|null $published_version_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $owner
 * @property-read WorkflowVersion|null $publishedVersion
 */
#[Fillable(['name', 'description', 'concurrency', 'retention_days', 'max_runs_per_minute', 'draft_graph', 'owner_id'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'concurrency' => ConcurrencyPolicy::class,
            'retention_days' => 'integer',
            'max_runs_per_minute' => 'integer',
            'draft_graph' => 'array',
            'draft_updated_at' => 'datetime',
        ];
    }

    public function draft(): WorkflowGraph
    {
        return WorkflowGraph::fromArray($this->draft_graph);
    }

    /**
     * The IDs of the credentials its nodes use, in the draft or the
     * published version. Load publishedVersion first when listing many.
     *
     * @return list<string>
     */
    public function credentialIds(): array
    {
        $registry = app(NodeRegistry::class);
        $graphs = array_filter([$this->draft(), $this->publishedVersion?->toGraph()]);
        $ids = [];

        foreach ($graphs as $graph) {
            foreach ($graph->nodes as $node) {
                if (! $registry->has($node->type)) {
                    continue;
                }

                foreach ($registry->make($node->type)->inputs() as $field) {
                    $value = $node->config[$field->name] ?? null;

                    if ($field->type === Field::CREDENTIAL && is_string($value) && $value !== '') {
                        $ids[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->publishedVersion === null
            || $this->publishedVersion->graph != $this->draft_graph;
    }

    /**
     * Retention in days for this workflow's execution logs: its own override,
     * else its workspace's. Null keeps logs forever.
     */
    public function effectiveRetentionDays(): ?int
    {
        return $this->retention_days ?? $this->workspace->effectiveRetentionDays();
    }

    public function hasRunInProgress(): bool
    {
        return $this->executions()->whereIn('status', ExecutionStatus::inProgress())->exists();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Published versions. Draft snapshots taken for test runs are left out.
     *
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->where('is_draft', false);
    }

    /**
     * Snapshots of the draft that test runs are pinned to.
     *
     * @return HasMany<WorkflowVersion, $this>
     */
    public function draftSnapshots(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->where('is_draft', true);
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'published_version_id');
    }

    /**
     * @return HasMany<WorkflowTrigger, $this>
     */
    public function triggers(): HasMany
    {
        return $this->hasMany(WorkflowTrigger::class);
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }
}
