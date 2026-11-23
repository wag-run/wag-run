<?php

namespace App\Services;

use App\Engine\Engine;
use App\Enums\ExecutionStatus;
use App\Enums\WorkspacePermission;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Registrar;

/**
 * What the REST API and the MCP server can do, in one place: both are thin
 * wrappers around these methods, so they find, authorize and validate the
 * same way.
 *
 * Every method takes the user the request authenticated as. It checks the
 * user's workspace role, like the app does, and the token's scope: a
 * personal access token carries the scopes it was created with, and a token
 * an MCP client got through OAuth carries "mcp:use", which allows them all.
 */
class WorkflowApi
{
    public const SCOPE_WORKFLOWS_READ = 'workflows:read';

    public const SCOPE_RUNS_READ = 'runs:read';

    public const SCOPE_RUNS_WRITE = 'runs:write';

    public const SCOPE_MCP = Registrar::OAUTH_SCOPE;

    public const TRIGGER_TYPE = 'api';

    public function __construct(
        protected ManualRuns $manualRuns,
        protected Engine $engine,
    ) {}

    /**
     * Every scope a token can have, with what it allows.
     *
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_WORKFLOWS_READ => __('api.scopes.workflows_read'),
            self::SCOPE_RUNS_READ => __('api.scopes.runs_read'),
            self::SCOPE_RUNS_WRITE => __('api.scopes.runs_write'),
            self::SCOPE_MCP => __('api.scopes.mcp_use'),
        ];
    }

    /**
     * The scopes a person can pick for a personal access token.
     *
     * @return array<string, string>
     */
    public static function personalTokenScopes(): array
    {
        return array_diff_key(self::scopes(), [self::SCOPE_MCP => true]);
    }

    /**
     * @return Collection<int, Workspace>
     */
    public function workspaces(User $user): Collection
    {
        $this->requireScope($user, self::SCOPE_WORKFLOWS_READ);

        return $user->accessibleWorkspaces();
    }

    /**
     * The workspace with this slug, among those the user belongs to.
     *
     * @throws ModelNotFoundException
     */
    public function workspace(User $user, string $slug): Workspace
    {
        return $user->accessibleWorkspaces()->firstWhere('slug', $slug)
            ?? throw (new ModelNotFoundException)->setModel(Workspace::class, [$slug]);
    }

    /**
     * @return Collection<int, Workflow>
     */
    public function workflows(User $user, Workspace $workspace): Collection
    {
        $this->authorize($user, self::SCOPE_WORKFLOWS_READ, WorkspacePermission::ViewWorkflows, $workspace);

        return $workspace->workflows()->with('publishedVersion')->orderBy('name')->get()
            ->each->setRelation('workspace', $workspace);
    }

    public function workflow(User $user, Workspace $workspace, string $id): Workflow
    {
        $this->authorize($user, self::SCOPE_WORKFLOWS_READ, WorkspacePermission::ViewWorkflows, $workspace);

        return $workspace->workflows()->with('publishedVersion')->findOrFail($this->uuid($id, Workflow::class))->setRelation('workspace', $workspace);
    }

    /**
     * The inputs a run of the workflow takes: its Manual trigger's fields.
     *
     * @return list<array<string, mixed>>
     */
    public function inputFields(Workflow $workflow): array
    {
        return $this->manualRuns->fields($workflow);
    }

    /**
     * Start a run of the workflow's published version.
     *
     * @param  array<string, mixed>  $input  values for the Manual trigger's fields
     * @param  Execution|null  $startedBy  the run of this install that made the request, if any
     *
     * @throws ValidationException when the workflow is not published or the input does not match its fields
     */
    public function startRun(User $user, Workspace $workspace, string $workflowId, array $input = [], ?Execution $startedBy = null): Execution
    {
        $this->authorize($user, self::SCOPE_RUNS_WRITE, WorkspacePermission::RunWorkflows, $workspace);
        $workflow = $workspace->workflows()->with('publishedVersion')->findOrFail($this->uuid($workflowId, Workflow::class));

        return $this->manualRuns->start($workflow, $user, $input, self::TRIGGER_TYPE, $startedBy)
            ->refresh()
            ->setRelations(['workspace' => $workspace, 'workflow' => $workflow]);
    }

    /**
     * The workspace's runs, newest first.
     *
     * @param  array<string, mixed>  $filters  workflow (an id), status, per_page (1-100), page
     * @return LengthAwarePaginator<int, Execution>
     *
     * @throws ValidationException when a filter is invalid
     */
    public function runs(User $user, Workspace $workspace, array $filters = []): LengthAwarePaginator
    {
        $this->authorize($user, self::SCOPE_RUNS_READ, WorkspacePermission::ViewExecutions, $workspace);

        $filters = Validator::make($filters, [
            'workflow' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(ExecutionStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $runs = $workspace->executions()
            ->with('workflow')
            ->when($filters['workflow'] ?? null, fn ($query, string $workflow) => $query->where('workflow_id', $workflow))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25), page: (int) ($filters['page'] ?? 1));

        $runs->getCollection()->each->setRelation('workspace', $workspace);

        return $runs;
    }

    /**
     * A run with its steps.
     */
    public function run(User $user, Workspace $workspace, string $id): Execution
    {
        $this->authorize($user, self::SCOPE_RUNS_READ, WorkspacePermission::ViewExecutions, $workspace);

        return $workspace->executions()->with(['workflow', 'steps' => fn ($query) => $query->orderBy('id')])->findOrFail($this->uuid($id, Execution::class))
            ->setRelation('workspace', $workspace);
    }

    /**
     * Cancel a run in progress. A run that already finished is left as it is.
     */
    public function cancelRun(User $user, Workspace $workspace, string $id): Execution
    {
        $this->authorize($user, self::SCOPE_RUNS_WRITE, WorkspacePermission::CancelExecutions, $workspace);
        $execution = $workspace->executions()->findOrFail($this->uuid($id, Execution::class));

        if ($execution->isInProgress()) {
            $this->engine->cancel($execution, $user);
        }

        return $execution->refresh()->load(['workflow', 'steps' => fn ($query) => $query->orderBy('id')])
            ->setRelation('workspace', $workspace);
    }

    /**
     * $id, when it is a UUID; else the model is not found, without asking a
     * database that would refuse to compare a UUID column with it.
     *
     * @param  class-string<Model>  $model
     *
     * @throws ModelNotFoundException
     */
    protected function uuid(string $id, string $model): string
    {
        return Str::isUuid($id) ? $id : throw (new ModelNotFoundException)->setModel($model, [$id]);
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(User $user, string $scope, WorkspacePermission $permission, Workspace $workspace): void
    {
        $this->requireScope($user, $scope);

        if (! $user->hasWorkspacePermission($workspace, $permission)) {
            throw new AuthorizationException(__('api.not_allowed_in_workspace'));
        }
    }

    /**
     * @throws AuthorizationException
     */
    protected function requireScope(User $user, string $scope): void
    {
        if (! $user->tokenCan($scope) && ! $user->tokenCan(self::SCOPE_MCP)) {
            throw new AuthorizationException(__('api.token_missing_scope', ['scope' => $scope]));
        }
    }
}
