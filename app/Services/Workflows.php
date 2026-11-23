<?php

namespace App\Services;

use App\Engine\Graph\GraphValidator;
use App\Engine\Graph\WorkflowGraph;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafts, publishing, version history and rollback. Edits stay in the draft
 * until an editor or admin publishes; each publish is a new immutable version.
 */
class Workflows
{
    public function __construct(
        protected GraphValidator $validator,
        protected TriggerSync $triggers,
    ) {}

    public function create(Workspace $workspace, string $name, User $owner, ?WorkflowGraph $graph = null): Workflow
    {
        $workflow = $workspace->workflows()->create([
            'name' => $name,
            'owner_id' => $owner->id,
            'draft_graph' => ($graph ?? WorkflowGraph::empty())->toArray(),
        ]);

        Audit::record('workflow.created', $workflow, $workspace);

        return $workflow;
    }

    public function saveDraft(Workflow $workflow, WorkflowGraph $graph): void
    {
        $workflow->forceFill([
            'draft_graph' => $graph->toArray(),
            'draft_updated_at' => now(),
        ])->save();
    }

    /**
     * Publish the draft as the next version. New runs use it; runs already in
     * progress stay on the version they started with.
     *
     * @throws ValidationException when the draft cannot run
     */
    public function publish(Workflow $workflow, ?User $publisher = null, ?string $note = null): WorkflowVersion
    {
        $graph = $workflow->draft();
        $errors = $this->validator->validate($graph);

        if ($errors !== []) {
            throw ValidationException::withMessages(['graph' => $errors]);
        }

        return DB::transaction(function () use ($workflow, $graph, $publisher, $note) {
            $version = $this->newVersion($workflow, $graph, $publisher, $note);

            Audit::record('workflow.published', $workflow, $workflow->workspace, ['version' => $version->number]);

            return $version;
        });
    }

    /**
     * Roll back by publishing an earlier version's graph as a new version, so
     * history stays linear. The draft is reset to match.
     */
    public function rollback(Workflow $workflow, WorkflowVersion $target, ?User $publisher = null): WorkflowVersion
    {
        if ($target->workflow_id !== $workflow->id) {
            throw ValidationException::withMessages(['version' => __('workflows.that_version_belongs_to_another')]);
        }

        return DB::transaction(function () use ($workflow, $target, $publisher) {
            $version = $this->newVersion($workflow, $target->toGraph(), $publisher, __('workflows.rollback_to_version_number', ['number' => $target->number]));

            $workflow->forceFill(['draft_graph' => $target->graph, 'draft_updated_at' => now()])->save();

            Audit::record('workflow.rolled_back', $workflow, $workflow->workspace, ['to' => $target->number, 'version' => $version->number]);

            return $version;
        });
    }

    /**
     * Throw away unpublished edits: the draft goes back to the published
     * version. Nothing happens to a workflow that was never published.
     */
    public function discardDraft(Workflow $workflow): void
    {
        $published = $workflow->publishedVersion;

        if (! $published) {
            return;
        }

        $workflow->forceFill(['draft_graph' => $published->graph, 'draft_updated_at' => now()])->save();

        Audit::record('workflow.draft_discarded', $workflow, $workflow->workspace, ['version' => $published->number]);
    }

    /**
     * A frozen copy of the draft for a test run to pin to. The latest copy is
     * reused while the draft has not changed since.
     *
     * @throws ValidationException when the draft cannot run
     */
    public function draftSnapshot(Workflow $workflow, ?User $user = null): WorkflowVersion
    {
        $graph = $workflow->draft();
        $errors = $this->validator->validate($graph);

        if ($errors !== []) {
            throw ValidationException::withMessages(['graph' => $errors]);
        }

        $latest = $workflow->draftSnapshots()->latest('id')->first();

        if ($latest && $latest->graph == $graph->toArray()) {
            return $latest;
        }

        return $workflow->draftSnapshots()->create([
            'is_draft' => true,
            'graph' => $graph->toArray(),
            'created_by' => $user?->id,
        ]);
    }

    public function delete(Workflow $workflow): void
    {
        Audit::record('workflow.deleted', $workflow, $workflow->workspace, label: $workflow->name);

        app(WorkflowFiles::class)->deleteForExecutions($workflow->executions()->pluck('id'));
        $workflow->delete();
    }

    protected function newVersion(Workflow $workflow, WorkflowGraph $graph, ?User $publisher, ?string $note): WorkflowVersion
    {
        $number = (int) $workflow->versions()->lockForUpdate()->max('number') + 1;

        $version = $workflow->versions()->create([
            'number' => $number,
            'graph' => $graph->toArray(),
            'created_by' => $publisher?->id,
            'note' => $note,
        ]);

        $workflow->forceFill(['published_version_id' => $version->id])->save();
        $workflow->setRelation('publishedVersion', $version);

        $this->triggers->sync($workflow);

        return $version;
    }
}
