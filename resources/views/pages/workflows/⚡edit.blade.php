<?php

use App\Engine\Definitions\Field;
use App\Engine\Editor\CanvasMapper;
use App\Engine\Editor\ConfigForm;
use App\Engine\Editor\ConnectionRules;
use App\Engine\Editor\DataPicker;
use App\Engine\Editor\ExpressionPreview;
use App\Engine\Editor\FieldFacts;
use App\Engine\Editor\NodeRenamer;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\GraphVariable;
use App\Engine\Graph\StoreReferenceValidator;
use App\Engine\Graph\VariableValidator;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\Masking\MaskPath;
use App\Engine\Masking\MaskRules;
use App\Engine\Node;
use App\Engine\NodeRegistry;
use App\Engine\Redactor;
use App\Enums\ConcurrencyPolicy;
use App\Enums\NodeKind;
use App\Enums\ValueType;
use App\Models\ExecutionStep;
use App\Models\Organization;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Engine\RunVariables;
use App\Services\DataStore;
use App\Services\ManualRuns;
use App\Services\OAuthConnections;
use App\Services\Workflows;
use App\Support\Audit;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use ArtisanFlow\WireFlow\View\Components\WireFlow;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads, WithWireFlow;

    public Workspace $workspace;

    public Workflow $workflow;

    public ?string $selected = null;

    /**
     * The result of connecting an account from a node, shown once after the provider sends the person back.
     *
     * @var array{variant: string, text: string}|null
     */
    public ?array $connectionResult = null;

    public string $nodeName = '';

    /**
     * Whether the selected node's ID is open for editing. The ID only changes
     * when the person saves, since references to it are rewritten then.
     */
    public bool $editingNodeId = false;

    public string $newNodeId = '';

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * @var array{max_retries: int|string, backoff_seconds: int|string}
     */
    public array $retry = ['max_retries' => 0, 'backoff_seconds' => 30];

    /**
     * Whether the selected node retries when it fails. Turning it on starts
     * at three retries; turning it off saves no retries.
     */
    public bool $retryEnabled = false;

    public bool $errorPath = false;

    /**
     * Whether the selected node waits for every incoming branch and runs once.
     */
    public bool $waitForAll = false;

    /**
     * The path being typed for the selected node's "Always mask" and
     * "Never mask" lists.
     *
     * @var array{always: string, never: string}
     */
    public array $newMaskPath = ['always' => '', 'never' => ''];

    public string $name = '';

    public string $description = '';

    public string $concurrency = 'allow';

    public bool $enabled = true;

    public bool $customRetention = false;

    public ?int $retentionDays = null;

    public bool $customRunLimit = false;

    public int|string|null $maxRunsPerMinute = null;

    /**
     * The name of the workflow variable being edited, or null while adding one.
     */
    #[Locked]
    public ?string $editingVariable = null;

    /**
     * @var array{name: string, type: string, value: string}
     */
    public array $variableForm = ['name' => '', 'type' => 'string', 'value' => ''];

    /**
     * @var list<string>
     */
    public array $publishErrors = [];

    /**
     * Values for the Manual trigger's inputs, asked for before a run.
     *
     * @var array<string, mixed>
     */
    public array $runValues = [];

    /**
     * Values for the draft's Manual trigger, asked for before a test run.
     *
     * @var array<string, mixed>
     */
    public array $testValues = [];

    /**
     * Sample data, as JSON, for a test run of a draft without a Manual trigger.
     */
    public string $testPayload = '';

    /**
     * Why the draft cannot be test-run yet.
     *
     * @var list<string>
     */
    public array $testErrors = [];

    /**
     * Whether the session clipboard holds copied nodes, for the Paste menu items.
     */
    public bool $hasClipboard = false;

    public bool $canUndo = false;

    public bool $canRedo = false;

    /**
     * Draft states to step back to and forward again, newest last.
     */
    public const HISTORY_LIMIT = 50;

    public const CLIPBOARD_KEY = 'workflow_editor.clipboard';

    public function mount(): void
    {
        session()->forget([$this->historyKey('undo'), $this->historyKey('redo')]);
        $this->hasClipboard = session()->has(self::CLIPBOARD_KEY);
        $this->name = $this->workflow->name;
        $this->description = (string) $this->workflow->description;
        $this->concurrency = $this->workflow->concurrency->value;
        $this->enabled = $this->workflow->is_enabled;
        $this->customRetention = $this->workflow->retention_days !== null;
        $this->retentionDays = $this->workflow->retention_days ?? $this->workspace->effectiveRetentionDays();
        $this->customRunLimit = $this->workflow->max_runs_per_minute !== null;
        $this->maxRunsPerMinute = $this->workflow->max_runs_per_minute ?? $this->workflow->workspace->organization->max_runs_per_minute ?? \App\Engine\Engine::DEFAULT_MAX_RUNS_PER_MINUTE;

        if (session()->has('oauth_status') || session()->has('oauth_error')) {
            $this->connectionResult = session()->has('oauth_error')
                ? ['variant' => 'danger', 'text' => (string) session('oauth_error')]
                : ['variant' => 'success', 'text' => (string) session('oauth_status')];
        }

        if (is_string($node = session('editor_select_node'))) {
            $this->selectNode($node);
        }
    }

    /**
     * Whether the person can connect accounts from a node's account field:
     * they must be able to edit the workflow and manage the workspace's
     * authentication settings.
     */
    #[Computed]
    public function canConnectAccounts(): bool
    {
        return $this->canEdit && Auth::user()->can('manage-credentials', $this->workspace);
    }

    /**
     * Connect a new account for the selected node's account field, such as
     * a GitHub account: off to the provider, and back to this node with the
     * new setting filled in.
     */
    public function connectAccount(string $fieldName, OAuthConnections $connections): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        $this->authorize('manage-credentials', $this->workspace);

        $field = collect($this->definition?->inputs() ?? [])->first(fn (Field $field) => $field->name === $fieldName && $field->type === Field::CREDENTIAL);
        $type = $field?->connectableOAuthType();

        abort_unless($this->selected && $type && $type->isConfigured(), 404);

        $this->redirect($connections->beginForNode($type, $this->workflow, $this->selected, $fieldName, Auth::user()));
    }

    #[Computed]
    public function graph(): WorkflowGraph
    {
        return $this->workflow->draft();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Auth::user()->can('edit-workflows', $this->workspace);
    }

    #[Computed]
    public function canRun(): bool
    {
        return $this->workflow->publishedVersion !== null && Auth::user()->can('run-workflows', $this->workspace);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function runFields(): array
    {
        return app(ManualRuns::class)->fields($this->workflow);
    }

    /**
     * Run the published version, first asking for the Manual trigger's
     * inputs when it declares any.
     */
    public function startRun(ManualRuns $runs): void
    {
        $this->authorize('run-workflows', $this->workspace);

        if ($this->runFields === []) {
            $this->run($runs);

            return;
        }

        $this->runValues = [];

        foreach ($this->runFields as $field) {
            $this->runValues[$field['name']] = ($field['type'] ?? 'text') === 'checkbox' ? false : null;
        }

        Flux::modal('run')->show();
    }

    public function run(ManualRuns $runs): void
    {
        $this->authorize('run-workflows', $this->workspace);
        $this->resetValidation();

        try {
            $execution = $runs->start($this->workflow->refresh(), Auth::user(), $this->runValues);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key) => [$key === 'run' ? 'run' : "runValues.{$key}" => $messages])
                ->all());
        }

        $this->redirectRoute('workspaces.executions.show', [$this->workspace, $execution], navigate: true);
    }

    /**
     * Whether the person can test-run the draft: they need to be able to edit
     * the workflow as well as run it.
     */
    #[Computed]
    public function canTest(): bool
    {
        return Auth::user()->can('edit-workflows', $this->workspace) && Auth::user()->can('run-workflows', $this->workspace);
    }

    /**
     * The inputs the draft's Manual trigger asks for.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function testFields(): array
    {
        return app(ManualRuns::class)->fields($this->workflow, $this->graph);
    }

    /**
     * The trigger a test run starts at, when the draft has no Manual trigger:
     * it receives the sample data given as JSON.
     */
    #[Computed]
    public function testTriggerName(): ?string
    {
        $trigger = app(ManualRuns::class)->testTrigger($this->workflow);

        return $trigger && $trigger->type !== \App\Engine\Nodes\Triggers\ManualTrigger::type() ? $trigger->name : null;
    }

    /**
     * Test-run the draft, first asking for its Manual trigger's inputs, or for
     * sample data when it starts with another trigger.
     */
    public function startTestRun(ManualRuns $runs): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        $this->authorize('run-workflows', $this->workspace);
        $this->resetValidation();
        $this->testErrors = [];

        if ($this->testFields === [] && $this->testTriggerName === null) {
            $this->runTest($runs);

            return;
        }

        $this->testValues = [];

        foreach ($this->testFields as $field) {
            $this->testValues[$field['name']] = ($field['type'] ?? 'text') === 'checkbox' ? false : null;
        }

        if ($this->testTriggerName !== null && trim($this->testPayload) === '') {
            $this->testPayload = $this->samplePayload();
        }

        Flux::modal('test-run')->show();
    }

    public function runTest(ManualRuns $runs): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        $this->authorize('run-workflows', $this->workspace);
        $this->resetValidation();

        try {
            $execution = $runs->startTest($this->workflow->refresh(), Auth::user(), $this->testValues, $this->testPayload);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            if (isset($errors['graph'])) {
                $this->testErrors = $errors['graph'];
                Flux::modal('test-run')->close();

                return;
            }

            throw ValidationException::withMessages(collect($errors)
                ->mapWithKeys(fn (array $messages, string $key) => [$key === 'payload' ? 'testPayload' : "testValues.{$key}" => $messages])
                ->all());
        }

        $this->redirectRoute('workspaces.executions.show', [$this->workspace, $execution], navigate: true);
    }

    /**
     * A starting point for the sample data, shaped like what the trigger
     * normally receives.
     */
    protected function samplePayload(): string
    {
        $trigger = app(ManualRuns::class)->testTrigger($this->workflow);

        $sample = match ($trigger?->type) {
            'core.webhook_trigger' => ['method' => 'POST', 'headers' => (object) [], 'query' => (object) [], 'body' => (object) []],
            'core.form_trigger' => ['values' => (object) collect((array) ($trigger->config['fields'] ?? []))->filter(fn ($field) => is_array($field) && filled($field['name'] ?? null))->mapWithKeys(fn ($field) => [$field['name'] => ''])->all()],
            'core.schedule_trigger' => ['scheduled_at' => now()->toIso8601String()],
            default => (object) [],
        };

        return (string) json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    #[Computed]
    public function canPublish(): bool
    {
        return Auth::user()->can('publish-workflows', $this->workspace);
    }

    /**
     * Canvas options. The editor handles copy, cut, paste, undo and redo
     * itself; editors also drop nodes from the palette, and deleting nodes
     * with the keyboard asks first.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function canvasConfig(): array
    {
        $config = ['keyboardShortcuts' => ['copy' => null, 'paste' => null, 'cut' => null, 'undo' => null, 'redo' => null]];

        if (! $this->canEdit) {
            return $config;
        }

        return [
            ...$config,
            'onDrop' => WireFlow::js('(event) => { if (event.data?.type) { $wire.addNode(event.data.type, event.position) } }'),
            'onBeforeDelete' => WireFlow::js('(change) => change.nodes.length === 0 ? change : Alpine.$data(document.querySelector("[data-editor-root]")).confirmNodeDelete(change.nodes.length).then((ok) => ok ? change : false)'),
        ];
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    #[Computed]
    public function canvas(): array
    {
        $canvas = app(CanvasMapper::class)->toCanvas($this->graph);
        $canvas['nodes'] = array_map(fn (array $node) => $this->withIcon($node), $canvas['nodes']);

        return $canvas;
    }

    /**
     * The palette's folders, keyed by their path joined with " › " ('' for
     * the top level), with groups of triggers first. Each lists the groups
     * nested in it, with how many nodes each holds, and the nodes directly
     * in it. Sticky notes are left out: they are added from the canvas's
     * context menu.
     *
     * @return array<string, array{name: string, groups: list<array{key: string, name: string, icon: string, count: int}>, items: list<array{type: string, label: string, description: string, icon: string}>}>
     */
    #[Computed]
    public function palette(): array
    {
        $entries = ['triggers' => [], 'actions' => []];

        foreach (app(NodeRegistry::class)->all() as $type => $node) {
            if ($node->kind() === NodeKind::Note) {
                continue;
            }

            $entries[$node->kind() === NodeKind::Trigger ? 'triggers' : 'actions'][] = [
                $node->paletteGroup(),
                ['type' => $type, 'label' => $node->label(), 'description' => $node->description(), 'icon' => $node->icon()],
            ];
        }

        $folders = ['' => ['name' => '', 'groups' => [], 'items' => []]];

        foreach ([...$entries['triggers'], ...$entries['actions']] as [$path, $item]) {
            $parent = '';

            foreach ($path as $depth => $name) {
                $key = implode(' › ', array_slice($path, 0, $depth + 1));
                $folders[$key] ??= ['name' => $name, 'groups' => [], 'items' => []];
                $folders[$parent]['groups'][$key] ??= ['key' => $key, 'name' => $name, 'icon' => $item['icon'], 'count' => 0];
                $folders[$parent]['groups'][$key]['count']++;
                $parent = $key;
            }

            $folders[$parent]['items'][] = $item;
        }

        return array_map(fn (array $folder) => [...$folder, 'groups' => array_values($folder['groups'])], $folders);
    }

    #[Computed]
    public function definition(): ?Node
    {
        $node = $this->selectedNode();

        return $node && app(NodeRegistry::class)->has($node->type) ? app(NodeRegistry::class)->make($node->type) : null;
    }

    /**
     * @return list<array{label: string, items: list<array{label: string, path: string, type: string}>}>
     */
    #[Computed]
    public function picker(): array
    {
        return $this->selected ? app(DataPicker::class)->optionsFor($this->graph, $this->selected, $this->workspace) : [];
    }

    /**
     * What a templated field of the selected node would become, worked out
     * with the data of the workflow's latest run, for the field's preview.
     *
     * @return array{status: 'value'|'empty'|'error', value: string, type: string, source: string}|null
     */
    #[Renderless]
    public function previewTemplate(string $template): ?array
    {
        if (! $this->selectedNode() || ! str_contains($template, '{{') || mb_strlen($template) > 20000) {
            return null;
        }

        return app(ExpressionPreview::class)->preview($this->workflow, $this->selected, $template);
    }

    /**
     * What the variable in the form would start a run as, worked out with
     * the latest run's trigger input and the data store as it is now, for
     * the starting-value field's preview.
     *
     * @return array{status: 'value'|'empty'|'error', value: string, type: string, source: string}|null
     */
    #[Renderless]
    public function previewStartingValue(string $template): ?array
    {
        if (! str_contains($template, '{{') || mb_strlen($template) > RunVariables::STARTING_VALUE_MAX_LENGTH) {
            return null;
        }

        $variable = new GraphVariable((string) ($this->editingVariable ?? $this->variableForm['name']), (string) $this->variableForm['type'], $template);

        return app(ExpressionPreview::class)->previewStartingValue($this->workflow, $variable);
    }

    /**
     * Choices for credential, secret, workflow and user fields. Settings are
     * listed by description and stored by UUID.
     *
     * @return array{credentials: array<string, array{type: string, label: string}>, secrets: array<string, string>, storeEntries: array<string, array{type: \App\Enums\ValueType, collection: bool, read_only: bool}>, workflows: array<string, string>, users: array<string, string>}
     */
    #[Computed]
    public function choices(): array
    {
        $credentials = $this->workspace->credentials()->orderBy('description')->get(['id', 'type', 'description']);

        return [
            'credentials' => $credentials->mapWithKeys(fn ($credential) => [$credential->id => ['type' => $credential->type, 'label' => $credential->description]])->all(),
            'secrets' => $this->workspace->secrets()->orderBy('key')->pluck('key', 'key')->all(),
            'storeEntries' => FieldFacts::storeEntries($this->workspace),
            'workflows' => $this->workspace->workflows()->whereKeyNot($this->workflow->id)->orderBy('name')->pluck('name', 'id')->all(),
            'users' => $this->workflow->workspace->organization->users()->wherePivotNull('deactivated_at')->orderBy('name')->get(['users.id', 'name', 'email'])
                ->mapWithKeys(fn ($user) => [$user->id => "{$user->name} ({$user->email})"])->all() ?? [],
        ];
    }

    /**
     * What a workflow variable's starting value can reference.
     *
     * @return list<array{label: string, items: list<array{label: string, path: string, type: string}>}>
     */
    #[Computed]
    public function startingValuePicker(): array
    {
        return app(DataPicker::class)->optionsForStartingValue($this->graph, $this->workspace);
    }

    #[Computed]
    public function versions()
    {
        return $this->workflow->versions()->with('creator')->orderByDesc('number')->get();
    }

    /**
     * What the selected node's reference fields point at, for fields that
     * depend on them.
     *
     * @return array<string, string>
     */
    public function fieldFacts(): array
    {
        return $this->definition ? FieldFacts::for($this->definition, $this->form, $this->choices['storeEntries'], $this->graph->variables) : [];
    }

    /**
     * What is wrong with the selected node's use of workflow variables and
     * the data store, such as an entry that doesn't exist. These block
     * publishing.
     *
     * @return list<string>
     */
    public function nodeProblems(): array
    {
        if (! $this->selected) {
            return [];
        }

        return [
            ...(app(VariableValidator::class)->problems($this->graph, $this->selected)[$this->selected] ?? []),
            ...(app(StoreReferenceValidator::class)->problems($this->graph, $this->workspace, $this->selected)[$this->selected] ?? []),
        ];
    }

    /**
     * The inspector's tabs for the selected node, in order. A tab that would
     * be empty is left out: Settings when none of the node's fields shows,
     * Behavior on a trigger, which takes no retries, error path, or
     * waiting for branches (only actions do), and Masking on a node with
     * no outputs.
     *
     * @return list<'settings'|'behavior'|'outputs'|'masking'>
     */
    #[Computed]
    public function inspectorTabs(): array
    {
        $definition = $this->definition;

        if (! $definition) {
            return [];
        }

        $values = [...$this->form, ...$this->fieldFacts()];
        $hasFields = collect($definition->inputs())->contains(fn (Field $field) => $field->isVisible($values, $definition->inputs()));

        return array_values(array_filter([
            $hasFields ? 'settings' : null,
            $definition->kind() === NodeKind::Action ? 'behavior' : null,
            'outputs',
            $definition->outputs($this->selectedNode()?->config ?? []) !== [] ? 'masking' : null,
        ]));
    }

    /**
     * What is wrong with the workflow's variable declarations, by variable
     * name ('' for one that belongs to no variable). These block publishing.
     *
     * @return array<string, list<string>>
     */
    public function variableProblems(): array
    {
        return array_merge_recursive(
            app(VariableValidator::class)->declarationProblems($this->graph),
            app(StoreReferenceValidator::class)->declarationProblems($this->graph, $this->workspace),
        );
    }

    public function addVariable(): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $this->reset('editingVariable', 'variableForm');
        $this->resetValidation();
        Flux::modal('variable')->show();
    }

    public function editVariable(string $name): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $variable = $this->graph->variables[$name] ?? null;

        if (! $variable) {
            return;
        }

        $this->resetValidation();
        $this->editingVariable = $variable->name;
        $this->variableForm = $variable->toArray();
        Flux::modal('variable')->show();
    }

    /**
     * Declare a variable or change one's type and starting value. It is
     * saved with the draft, so it joins undo and takes effect when the
     * workflow is published.
     */
    public function saveVariable(): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $adding = $this->editingVariable === null;

        $this->validate([
            'variableForm.name' => $adding
                ? ['required', 'string', 'max:'.DataStore::KEY_MAX_LENGTH, 'regex:'.DataStore::KEY_PATTERN, Rule::notIn(array_keys($this->graph->variables))]
                : [],
            'variableForm.type' => ['required', Rule::enum(ValueType::class)],
            'variableForm.value' => ['nullable', 'string', 'max:'.RunVariables::STARTING_VALUE_MAX_LENGTH],
        ], [
            'variableForm.name.regex' => __('common.use_letters_numbers_and_underscores'),
            'variableForm.name.not_in' => __('workflows.edit.variable_name_taken'),
        ], [
            'variableForm.name' => __('common.name'),
            'variableForm.type' => __('common.type'),
            'variableForm.value' => __('workflows.edit.starting_value'),
        ]);

        if ($adding && count($this->graph->variables) >= (int) config('workflows.variables.max_count')) {
            $this->addError('variableForm.name', __('workflows.editor.too_many_variables', ['max' => (int) config('workflows.variables.max_count')]));

            return;
        }

        $variable = new GraphVariable($adding ? $this->variableForm['name'] : $this->editingVariable, $this->variableForm['type'], (string) $this->variableForm['value']);
        $problems = app(VariableValidator::class)->startingValueProblems($variable);

        if ($problems !== []) {
            $this->addError('variableForm.value', $problems[0]);

            return;
        }

        $this->save($this->graph->withVariable($variable));

        Flux::modal('variable')->close();
        $this->reset('editingVariable', 'variableForm');
    }

    public function deleteVariable(string $name): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $this->save($this->graph->withoutVariable($name));
    }

    public function selectedNode(): ?GraphNode
    {
        return $this->selected && $this->graph->has($this->selected) ? $this->graph->node($this->selected) : null;
    }

    public function selectNode(string $nodeId): void
    {
        if (! $this->graph->has($nodeId)) {
            return;
        }

        $node = $this->graph->node($nodeId);
        $this->selected = $nodeId;
        $this->nodeName = $node->name;
        $this->retry = [
            'max_retries' => $node->retry['max_retries'] ?? 0,
            'backoff_seconds' => $node->retry['backoff_seconds'] ?? 30,
        ];
        $this->retryEnabled = (int) $this->retry['max_retries'] > 0;
        $this->errorPath = $node->errorPath;
        $this->waitForAll = $node->waitForAll;
        $this->form = $this->definition ? ConfigForm::toForm($this->definition, $node->config) : [];
        $this->editingNodeId = false;
        $this->newNodeId = $nodeId;
        $this->newMaskPath = ['always' => '', 'never' => ''];
        $this->resetValidation();
    }

    /**
     * Add the typed path to the selected node's "Always mask" or "Never
     * mask" list.
     */
    public function addMaskPath(string $list): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        abort_unless(in_array($list, ['always', 'never'], true), 404);

        try {
            $path = MaskPath::parse($this->newMaskPath[$list] ?? '')->toString();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(["newMaskPath.{$list}" => $e->getMessage()]);
        }

        $this->changeMasking($list, $path, adding: true);
        $this->newMaskPath[$list] = '';
        $this->resetValidation("newMaskPath.{$list}");
    }

    public function removeMaskPath(string $list, int $index): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        abort_unless(in_array($list, ['always', 'never'], true), 404);

        $path = $this->selectedNode()?->masking[$list][$index] ?? null;

        if ($path !== null) {
            $this->changeMasking($list, $path, adding: false);
        }
    }

    /**
     * Stop masking one of the names masked in the last run, at its path,
     * by adding the path to "Never mask".
     */
    public function dontMask(int $index): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        $row = $this->lastRunMasking['rows'][$index] ?? null;

        if ($row !== null && $row['by_name'] && ! $row['never']) {
            $this->changeMasking('never', $row['path'], adding: true);
        }
    }

    /**
     * What was masked in the selected node's output (a trigger's: the run's
     * trigger data) the last time it ran, one row per path with list
     * positions written as [*], and how many of them are masked.
     *
     * @return array{ran: bool, rows: list<array{path: string, reason: string, by_name: bool, never: bool}>, count: int}
     */
    #[Computed]
    public function lastRunMasking(): array
    {
        $node = $this->selectedNode();
        $definition = $this->definition;
        $none = ['ran' => false, 'rows' => [], 'count' => 0];

        if (! $node || ! $definition) {
            return $none;
        }

        if ($definition->kind() === NodeKind::Trigger) {
            $execution = $this->workflow->executions()->where('trigger_node_id', $node->id)->latest()->latest('id')->first();
            [$ran, $data] = [$execution !== null, $execution?->input];
        } else {
            $step = ExecutionStep::query()
                ->whereIn('execution_id', $this->workflow->executions()->select('id'))
                ->where('node_id', $node->id)
                ->whereNotNull('output')
                ->latest('id')
                ->first();
            [$ran, $data] = [$step !== null, $step?->output];
        }

        if (! $ran) {
            return $none;
        }

        $rows = [];

        foreach (Redactor::forWorkspace($this->workspace)->explain($data, MaskRules::for($node)) as $explained) {
            $path = MaskPath::wildcard($explained['path']);
            $rows[$path] ??= [
                'path' => $path,
                'reason' => match ($explained['reason']) {
                    'header' => __('workflows.edit.masking.reason_header', ['name' => $explained['detail']]),
                    'field' => __('workflows.edit.masking.reason_field', ['name' => $explained['detail']]),
                    'organization' => __('workflows.edit.masking.reason_organization', ['name' => $explained['detail']]),
                    'always' => __('workflows.edit.masking.reason_always'),
                    default => __('workflows.edit.masking.reason_value'),
                },
                'by_name' => in_array($explained['reason'], ['header', 'field', 'organization'], true),
                'never' => $explained['never'],
            ];
        }

        $rows = array_values($rows);

        return ['ran' => true, 'rows' => $rows, 'count' => count(array_filter($rows, fn (array $row) => ! $row['never']))];
    }

    /**
     * Add a path to, or remove it from, one of the selected node's masking
     * lists, and note it in the workspace's audit log.
     */
    protected function changeMasking(string $list, string $path, bool $adding): void
    {
        $node = $this->selectedNode();

        if (! $node) {
            return;
        }

        $lists = ['always' => $node->masking['always'] ?? [], 'never' => $node->masking['never'] ?? []];

        if ($adding === in_array($path, $lists[$list], true)) {
            return;
        }

        $lists[$list] = $adding ? [...$lists[$list], $path] : array_values(array_diff($lists[$list], [$path]));

        $this->save($this->graph->withNode($node->withMasking($lists['always'], $lists['never'])));
        unset($this->lastRunMasking);

        Audit::record($adding ? 'workflow.masking_added' : 'workflow.masking_removed', $this->workflow, $this->workspace, [
            'node' => $node->id,
            'list' => $list,
            'path' => $path,
        ]);
    }

    public function startEditingNodeId(): void
    {
        $this->editingNodeId = $this->selected !== null;
        $this->newNodeId = (string) $this->selected;
        $this->resetValidation('newNodeId');
    }

    public function cancelEditingNodeId(): void
    {
        $this->editingNodeId = false;
        $this->newNodeId = (string) $this->selected;
        $this->resetValidation('newNodeId');
    }

    /**
     * Give the selected node the ID typed in, moving its connections and
     * rewriting every reference to it in the draft.
     */
    public function saveNodeId(NodeRenamer $renamer): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $node = $this->selectedNode();

        if (! $node) {
            return;
        }

        $newId = trim($this->newNodeId);

        if ($problem = $renamer->check($this->graph, $node->id, $newId)) {
            $this->addError('newNodeId', $problem);

            return;
        }

        if ($newId === $node->id) {
            $this->cancelEditingNodeId();

            return;
        }

        $this->save($renamer->rename($this->graph, $node->id, $newId));
        $this->reloadCanvas();
        $this->selectNode($newId);
        $this->flowSelectNodes([$newId]);

        Flux::toast(variant: 'success', text: __('workflows.edit.id_changed_to', ['from' => $node->id, 'to' => $newId]));
    }

    public function deselect(): void
    {
        $this->selected = null;
        $this->form = [];
        $this->dispatch('wag-node-deselected');
    }

    /**
     * @return bool|array{allowed: false, reason: string}
     */
    public function canConnect(string $source, string $target, ?string $sourceHandle = null, ?string $targetHandle = null, ?string $replacing = null): bool|array
    {
        if (! $this->canEdit) {
            return ['allowed' => false, 'reason' => __('workflows.edit.you_can_view_this_workflow')];
        }

        $reason = app(ConnectionRules::class)->check($this->graph, $source, $sourceHandle ?: 'main', $target);

        return $reason === null ? true : ['allowed' => false, 'reason' => $reason];
    }

    public function onConnect(string $source, string $target, ?string $sourceHandle = null, ?string $targetHandle = null): void
    {
        $port = $sourceHandle ?: 'main';
        $rules = app(ConnectionRules::class);

        if ($rules->check($this->graph, $source, $port, $target) !== null) {
            $this->reloadCanvas();

            return;
        }

        $this->save($rules->connect($this->graph, $source, $port, $target));
        unset($this->picker);
    }

    /**
     * @param  array{x: float|int, y: float|int}  $position
     */
    public function moveNode(string $nodeId, array $position): void
    {
        if (! $this->canEdit || ! $this->graph->has($nodeId)) {
            return;
        }

        $node = $this->graph->node($nodeId);

        $this->save($this->graph->withNode(new GraphNode($node->id, $node->type, $node->name, $node->config, $node->retry, [
            'x' => round((float) ($position['x'] ?? 0)),
            'y' => round((float) ($position['y'] ?? 0)),
        ], $node->errorPath, $node->waitForAll, $node->masking)));
    }

    /**
     * @param  array{type?: string, nodes?: list<array<string, mixed>>, origin?: string}  $changes
     */
    public function nodesChanged(array $changes): void
    {
        if (! $this->canEdit || ($changes['type'] ?? null) !== 'remove') {
            return;
        }

        $graph = $this->graph;

        foreach ($changes['nodes'] ?? [] as $node) {
            $graph = $graph->withoutNode((string) ($node['id'] ?? ''));

            if (($node['id'] ?? null) === $this->selected) {
                $this->deselect();
            }
        }

        $this->save($graph);
    }

    /**
     * @param  array{type?: string, edges?: list<array<string, mixed>>, origin?: string}  $changes
     */
    public function edgesChanged(array $changes): void
    {
        if (! $this->canEdit || ($changes['type'] ?? null) !== 'remove') {
            return;
        }

        $graph = $this->graph;

        foreach ($changes['edges'] ?? [] as $edge) {
            $graph = $graph->withoutEdge((string) ($edge['source'] ?? ''), (string) (($edge['sourceHandle'] ?? null) ?: 'main'), (string) ($edge['target'] ?? ''));
        }

        if (count($graph->edges) !== count($this->graph->edges)) {
            $this->save($graph);
        }
    }

    /**
     * Add a node from the palette. Clicked, it goes after the selected node
     * and connects to it; dropped on the canvas, it goes where it was dropped,
     * unconnected.
     *
     * @param  array{x?: float|int, y?: float|int}|null  $droppedAt  flow coordinates of the drop
     */
    public function addNode(string $type, ?array $droppedAt = null): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $registry = app(NodeRegistry::class);
        abort_unless($registry->has($type), 404);

        $definition = $registry->make($type);
        $graph = $this->graph;
        $anchor = $droppedAt === null ? $this->selectedNode() : null;
        $siblings = $anchor ? count($graph->outgoing($anchor->id)) : 0;
        $position = match (true) {
            $droppedAt !== null => ['x' => round((float) ($droppedAt['x'] ?? 0)) - 110, 'y' => round((float) ($droppedAt['y'] ?? 0)) - 30],
            $anchor !== null => ['x' => $anchor->position['x'] + 300, 'y' => $anchor->position['y'] + ($siblings * 140)],
            default => ['x' => (collect($graph->nodes)->max(fn ($node) => $node->position['x']) ?? -220) + 300, 'y' => 160],
        };

        $id = $graph->freeId(str($type)->afterLast('.')->replace('_trigger', '')->toString());
        $config = collect($definition->inputs())->filter(fn (Field $field) => $field->default !== null)->mapWithKeys(fn (Field $field) => [$field->name => $field->default])->all();
        $node = new GraphNode($id, $type, $definition->labelFor($config), $config, position: $position);

        $graph = $graph->withNode($node);

        $port = $anchor ? $this->firstPort($anchor) : null;

        if ($anchor && $port && app(ConnectionRules::class)->check($graph, $anchor->id, $port, $id) === null) {
            $graph = app(ConnectionRules::class)->connect($graph, $anchor->id, $port, $id);
        }

        $this->save($graph);
        $this->reloadCanvas();
        $this->selectNode($id);
        $this->flowSelectNodes([$id]);

        if ($droppedAt === null) {
            $this->flowSetCenter($position['x'] + 110, $position['y'] + 40);
        }
    }

    /**
     * Step the draft back to before the last change.
     */
    public function undo(): void
    {
        $this->stepHistory('undo', 'redo');
    }

    /**
     * Re-apply the last change undone.
     */
    public function redo(): void
    {
        $this->stepHistory('redo', 'undo');
    }

    /**
     * Put nodes, and the connections between them, on the session clipboard.
     *
     * @param  list<string>  $nodeIds
     */
    public function copyNodes(array $nodeIds, string $toastKey = 'workflows.edit.copied_n_nodes'): void
    {
        $graph = $this->graph;
        $ids = array_values(array_filter(array_map('strval', $nodeIds), fn (string $id) => $graph->has($id)));

        if ($ids === []) {
            return;
        }

        session()->put(self::CLIPBOARD_KEY, [
            'nodes' => array_map(fn (string $id) => $graph->node($id)->toArray(), $ids),
            'edges' => array_values(array_map(fn ($edge) => $edge->toArray(), array_filter(
                $graph->edges,
                fn ($edge) => in_array($edge->source, $ids, true) && in_array($edge->target, $ids, true),
            ))),
            'pasted' => 0,
        ]);

        $this->hasClipboard = true;
        Flux::toast(text: trans_choice($toastKey, count($ids), ['count' => count($ids)]));
    }

    /**
     * @param  list<string>  $nodeIds
     */
    public function cutNodes(array $nodeIds): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        $this->copyNodes($nodeIds, 'workflows.edit.cut_n_nodes');

        $graph = $this->graph;

        foreach ($nodeIds as $id) {
            $graph = $graph->withoutNode((string) $id);
        }

        if (in_array($this->selected, $nodeIds, true)) {
            $this->deselect();
        }

        $this->save($graph);
        $this->reloadCanvas();
    }

    /**
     * Add copies of the clipboard's nodes with new ids. At a position (a
     * right-click on the canvas), the copies start there; otherwise they sit
     * a little below and right of the last paste.
     *
     * @param  array{x?: float|int, y?: float|int}|null  $at  flow coordinates
     */
    public function pasteNodes(?array $at = null): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $clipboard = session(self::CLIPBOARD_KEY);

        if (! is_array($clipboard) || ($clipboard['nodes'] ?? []) === []) {
            return;
        }

        $nodes = array_map(fn (array $node) => GraphNode::fromArray($node), $clipboard['nodes']);
        $pasted = (int) ($clipboard['pasted'] ?? 0) + 1;
        [$dx, $dy] = $at !== null
            ? [round((float) ($at['x'] ?? 0)) - min(array_map(fn ($node) => $node->position['x'], $nodes)), round((float) ($at['y'] ?? 0)) - min(array_map(fn ($node) => $node->position['y'], $nodes))]
            : [40 * $pasted, 40 * $pasted];

        $graph = $this->graph;
        $newIds = [];

        foreach ($nodes as $node) {
            $id = $graph->freeId(preg_replace('/_\d+$/', '', $node->id) ?: $node->id);
            $newIds[$node->id] = $id;
            $graph = $graph->withNode(new GraphNode($id, $node->type, $node->name, $node->config, $node->retry, [
                'x' => $node->position['x'] + $dx,
                'y' => $node->position['y'] + $dy,
            ], $node->errorPath, $node->waitForAll, $node->masking));
        }

        foreach ($clipboard['edges'] ?? [] as $edge) {
            $source = $newIds[$edge['source'] ?? ''] ?? null;
            $target = $newIds[$edge['target'] ?? ''] ?? null;

            if ($source && $target) {
                $graph = app(ConnectionRules::class)->connect($graph, $source, (string) ($edge['source_port'] ?? 'main'), $target);
            }
        }

        session()->put(self::CLIPBOARD_KEY, [...$clipboard, 'pasted' => $at === null ? $pasted : 0]);

        $this->save($graph);
        $this->reloadCanvas();
        $this->flowSelectNodes(array_values($newIds));

        if (count($newIds) === 1) {
            $this->selectNode(array_values($newIds)[0]);
        } else {
            $this->deselect();
        }
    }

    public function deleteSelected(): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        if ($this->selected) {
            $id = $this->selected;
            $this->save($this->graph->withoutNode($id));
            $this->deselect();
            $this->reloadCanvas();
        }
    }

    public function updatedForm(): void
    {
        $this->saveSelected();
    }

    public function updatedNodeName(): void
    {
        $this->saveSelected();
    }

    public function updatedRetry(): void
    {
        $this->saveSelected();
    }

    public function updatedRetryEnabled(): void
    {
        $this->retry['max_retries'] = $this->retryEnabled ? max(1, (int) $this->retry['max_retries'] ?: 3) : 0;
        $this->saveSelected();
    }

    public function updatedErrorPath(): void
    {
        $this->saveSelected();
    }

    public function updatedWaitForAll(): void
    {
        $this->saveSelected();
    }

    public function addRow(string $field): void
    {
        $definition = collect($this->definition?->inputs() ?? [])->firstWhere('name', $field);

        if (! $definition) {
            return;
        }

        $this->form[$field][] = $definition->type === Field::KEY_VALUE
            ? ['key' => '', 'value' => '']
            : collect($definition->fields)->mapWithKeys(fn (Field $sub) => [$sub->name => $sub->type === Field::BOOLEAN ? (bool) $sub->default : ($sub->default ?? '')])->all();

        $this->saveSelected();
    }

    public function removeRow(string $field, int $index): void
    {
        $node = $this->selectedNode();
        $moves = $node && $this->definition ? $this->definition->portsAfterRowRemoved($field, $index, $node->config) : [];

        if ($this->canEdit && $moves !== []) {
            $this->save($this->graphWithMovedPorts($node->id, $moves));
        }

        unset($this->form[$field][$index]);
        $this->form[$field] = array_values($this->form[$field] ?? []);

        $this->saveSelected();
    }

    /**
     * Save the workflow settings form. Turning the workflow on or off is part
     * of it, and needs permission to publish: off, its triggers, the API, and
     * other workflows cannot start it, though runs from the editor still can.
     */
    public function saveSettings(): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        if ($this->enabled !== $this->workflow->is_enabled) {
            $this->authorize('publish-workflows', $this->workspace);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'concurrency' => ['required', \Illuminate\Validation\Rule::enum(ConcurrencyPolicy::class)],
            'retentionDays' => [$this->customRetention ? 'required' : 'nullable', 'integer', 'min:1', 'max:3650'],
            'maxRunsPerMinute' => [$this->customRunLimit ? 'required' : 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $this->workflow->fill([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'concurrency' => $this->concurrency,
            'retention_days' => $this->customRetention ? $this->retentionDays : null,
            'max_runs_per_minute' => $this->customRunLimit ? (int) $this->maxRunsPerMinute : null,
        ]);

        $this->workflow->forceFill(['is_enabled' => $this->enabled]);

        if ($this->workflow->isDirty()) {
            $changed = array_keys($this->workflow->getDirty());
            $this->workflow->save();

            if (in_array('is_enabled', $changed, true)) {
                Audit::record($this->enabled ? 'workflow.enabled' : 'workflow.disabled', $this->workflow, $this->workspace);
                $changed = array_values(array_diff($changed, ['is_enabled']));
            }

            if ($changed !== []) {
                Audit::record('workflow.settings_updated', $this->workflow, $this->workspace, ['changed' => $changed]);
            }
        }

        Flux::toast(variant: 'success', text: __('workflows.edit.workflow_settings_saved'));
    }

    public function publish(Workflows $workflows): void
    {
        $this->authorize('publish-workflows', $this->workspace);

        try {
            $version = $workflows->publish($this->workflow, Auth::user());
        } catch (ValidationException $e) {
            $this->publishErrors = $e->errors()['graph'] ?? array_merge(...array_values($e->errors()));

            return;
        }

        $this->publishErrors = [];
        $this->workflow->refresh();
        unset($this->versions);
        Flux::toast(variant: 'success', text: __('workflows.edit.published_version_n_new_runs', ['n' => $version->number]));
    }

    /**
     * Put the draft back to the published version. The discarded draft goes
     * on the undo history, so Undo brings it back.
     */
    public function discardDraft(Workflows $workflows): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        if (! $this->workflow->publishedVersion || ! $this->workflow->hasUnpublishedChanges()) {
            return;
        }

        $this->pushHistory('undo', $this->graph->toArray());
        session()->forget($this->historyKey('redo'));

        $workflows->discardDraft($this->workflow);
        $this->workflow->refresh();
        unset($this->graph, $this->canvas);
        $this->refreshHistoryState();
        $this->reloadCanvas();
        $this->publishErrors = [];

        if ($this->selected && $this->graph->has($this->selected)) {
            $this->selectNode($this->selected);
        } else {
            $this->deselect();
        }

        Flux::toast(variant: 'success', text: __('workflows.edit.draft_discarded', ['n' => $this->workflow->publishedVersion->number]));
    }

    public function rollback(int $versionId, Workflows $workflows): void
    {
        $this->authorize('publish-workflows', $this->workspace);

        $target = $this->workflow->versions()->findOrFail($versionId);
        $version = $workflows->rollback($this->workflow, $target, Auth::user());

        $this->workflow->refresh();
        unset($this->graph, $this->versions, $this->canvas);
        $this->deselect();
        $this->reloadCanvas();
        Flux::modal('versions')->close();
        Flux::toast(variant: 'success', text: __('workflows.edit.rolled_back_to_version_old', ['old' => $target->number, 'new' => $version->number]));
    }

    protected function saveSelected(): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $node = $this->selectedNode();

        if (! $node || ! $this->definition) {
            return;
        }

        $this->validate([
            'nodeName' => ['required', 'string', 'max:100'],
            'retry.max_retries' => ['nullable', 'integer', 'min:0', 'max:25'],
            'retry.backoff_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $config = ConfigForm::toConfig($this->definition, $this->form, $this->fieldFacts());

        foreach ($this->definition->inputs() as $field) {
            if ($field->optionsWhen !== []) {
                $this->form[$field->name] = $config[$field->name] ?? $field->default;
            }
        }
        $retry = (int) $this->retry['max_retries'] > 0
            ? ['max_retries' => (int) $this->retry['max_retries'], 'backoff_seconds' => (int) ($this->retry['backoff_seconds'] ?? 30)]
            : null;

        // A node still called by its type's label keeps following that
        // label when a setting changes it, such as While loop to Do while loop.
        if ($this->nodeName === $this->definition->labelFor($node->config)) {
            $this->nodeName = $this->definition->labelFor($config);
        }

        $errorPath = $this->errorPath && $this->definition->hasErrorPort();
        $oldPorts = $this->definition->ports($node->config);
        $newPorts = $this->definition->ports($config);
        $outputsChanged = $oldPorts !== $newPorts || $errorPath !== $node->errorPath;
        $updated = new GraphNode($node->id, $node->type, $this->nodeName, $config, $retry, $node->position, $errorPath, $this->waitForAll && $this->definition->kind() === NodeKind::Action && ! $this->definition->joinsBranches(), $node->masking);

        $graph = $this->graph->withNode($updated);
        $relabelled = app(CanvasMapper::class)->node($node)['data']['ports'] !== app(CanvasMapper::class)->node($updated)['data']['ports'];

        if ($outputsChanged) {
            $graph = $graph->withoutEdgesFromPortsOtherThan($node->id, $errorPath ? [...$newPorts, 'error'] : $newPorts);
        }

        $this->save($graph);

        if ($outputsChanged || $relabelled) {
            $this->reloadCanvas();
        } else {
            $this->flowUpdateNode($node->id, ['data' => $this->withIcon(app(CanvasMapper::class)->node($updated))['data']]);
        }
    }

    /**
     * Re-attach a node's connections after its ports moved.
     *
     * @param  array<string, string|null>  $moves  old port => new port, null to drop
     */
    protected function graphWithMovedPorts(string $nodeId, array $moves): WorkflowGraph
    {
        $graph = $this->graph;
        $moved = array_values(array_filter($graph->outgoing($nodeId), fn ($edge) => array_key_exists($edge->sourcePort, $moves)));

        foreach ($moved as $edge) {
            $graph = $graph->withoutEdge($edge->source, $edge->sourcePort, $edge->target);
        }

        foreach ($moved as $edge) {
            if ($moves[$edge->sourcePort] !== null) {
                $graph = app(ConnectionRules::class)->connect($graph, $nodeId, $moves[$edge->sourcePort], $edge->target);
            }
        }

        return $graph;
    }

    protected function save(WorkflowGraph $graph, bool $record = true): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $current = $this->graph->toArray();

        if ($record && $current !== $graph->toArray()) {
            $this->pushHistory('undo', $current);
            session()->forget($this->historyKey('redo'));
        }

        app(Workflows::class)->saveDraft($this->workflow, $graph);
        unset($this->graph, $this->canvas, $this->picker, $this->startingValuePicker);
        $this->refreshHistoryState();
    }

    protected function refreshHistoryState(): void
    {
        $this->canUndo = session($this->historyKey('undo'), []) !== [];
        $this->canRedo = session($this->historyKey('redo'), []) !== [];
    }

    protected function historyKey(string $stack): string
    {
        return "workflow_editor.{$this->workflow->id}.{$stack}";
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    protected function pushHistory(string $stack, array $graph): void
    {
        $entries = session($this->historyKey($stack), []);
        $entries[] = $graph;

        session()->put($this->historyKey($stack), array_slice($entries, -self::HISTORY_LIMIT));
    }

    /**
     * Restore the newest draft on $from, keeping the current one on $to.
     */
    protected function stepHistory(string $from, string $to): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $entries = session($this->historyKey($from), []);

        if ($entries === []) {
            return;
        }

        $restored = array_pop($entries);
        session()->put($this->historyKey($from), $entries);
        $this->pushHistory($to, $this->graph->toArray());

        $this->save(WorkflowGraph::fromArray($restored), record: false);
        $this->reloadCanvas();

        if ($this->selected && $this->graph->has($this->selected)) {
            $this->selectNode($this->selected);
        } else {
            $this->deselect();
        }
    }

    protected function reloadCanvas(): void
    {
        unset($this->graph, $this->canvas);
        $this->flowFromObject($this->canvas);
    }

    /**
     * The output a newly added node attaches to: the first one with nothing
     * connected yet, else the first one.
     */
    protected function firstPort(GraphNode $node): string
    {
        $ports = app(NodeRegistry::class)->make($node->type)->ports($node->config);

        foreach ($ports as $port) {
            if ($this->graph->outgoing($node->id, $port) === []) {
                return $port;
            }
        }

        return $ports[0] ?? 'main';
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    protected function withIcon(array $node): array
    {
        $node['data']['iconHtml'] = Blade::render('<flux:icon :icon="$icon" variant="mini" class="size-4" />', ['icon' => $node['data']['icon']]);

        return $node;
    }

    public function render()
    {
        return $this->view()->title($this->workflow->name.' · '.$this->workspace->name);
    }
}; ?>

<div class="-m-6 flex h-screen flex-col lg:-m-8" x-data="wagEditorShortcuts(@js($this->canEdit), @js(['one' => __('workflows.edit.delete_node_question'), 'many' => __('workflows.edit.delete_count_nodes_question'), 'message' => __('workflows.edit.delete_nodes_message'), 'confirm' => __('workflows.edit.delete')]))" data-editor-root
    x-on:wag-open-node="$wire.selectNode($event.detail.id); $store.editorLayout.showSettingsForNode()" x-on:wag-node-deselected.window="$store.editorLayout.nodeDeselected()" x-on:keydown.window="onKeydown($event)">
    {{-- Top bar --}}
    <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('workspaces.workflows', $workspace)" wire:navigate :aria-label="__('workflows.edit.back_to_workflows')" />
        <div class="min-w-0">
            <flux:heading class="truncate">{{ $workflow->name }}</flux:heading>
        </div>
        @if ($workflow->publishedVersion)
            <flux:badge size="sm" color="green">{{ __('common.published_vn', ['n' => $workflow->publishedVersion->number]) }}</flux:badge>
        @endif
        @if ($workflow->hasUnpublishedChanges())
            <flux:badge size="sm" color="amber" data-test="draft-badge">{{ __('workflows.edit.unpublished_draft') }}</flux:badge>
            @if ($workflow->publishedVersion && $this->canEdit)
                <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workflows.edit.discard_draft_question'), 'message' => __('workflows.edit.discard_your_changes_and_go_back', ['n' => $workflow->publishedVersion->number]), 'confirm' => __('workflows.edit.discard_draft')]) }}).then((ok) => ok && $wire.discardDraft())"
                    data-test="discard-draft">{{ __('workflows.edit.discard_draft') }}</flux:button>
            @endif
        @endif
        <flux:text class="text-xs" wire:loading.remove wire:target="moveNode,onConnect,nodesChanged,edgesChanged,form,nodeName,saveNodeId,retry,retryEnabled,errorPath,waitForAll,addNode,undo,redo,cutNodes,pasteNodes,discardDraft">
            {{ $workflow->draft_updated_at ? __('workflows.edit.draft_saved_time', ['time' => $workflow->draft_updated_at->diffForHumans()]) : '' }}
        </flux:text>
        <flux:text class="text-xs" wire:loading wire:target="moveNode,onConnect,nodesChanged,edgesChanged,form,nodeName,saveNodeId,retry,retryEnabled,errorPath,waitForAll,addNode,undo,redo,cutNodes,pasteNodes,discardDraft">{{ __('workflows.edit.saving') }}</flux:text>

        <flux:spacer />

        @if ($this->canEdit)
            <flux:button.group>
                <flux:button size="sm" icon="arrow-uturn-left" wire:click="undo" :disabled="! $canUndo" :tooltip="__('workflows.edit.undo_shortcut')" :aria-label="__('workflows.edit.undo')" data-test="undo-button" />
                <flux:button size="sm" icon="arrow-uturn-right" wire:click="redo" :disabled="! $canRedo" :tooltip="__('workflows.edit.redo_shortcut')" :aria-label="__('workflows.edit.redo')" data-test="redo-button" />
            </flux:button.group>
        @endif
        <flux:button size="sm" icon="play-circle" :href="route('workspaces.executions', [$workspace, 'workflow' => $workflow->id])" wire:navigate>{{ __('common.runs') }}</flux:button>
        <flux:modal.trigger name="versions">
            <flux:button size="sm" icon="clock">{{ __('workflows.edit.versions') }}</flux:button>
        </flux:modal.trigger>
        <flux:button size="sm" icon="arrow-down-tray" :href="route('workspaces.workflows.export', [$workspace, $workflow])">{{ __('workflows.edit.export') }}</flux:button>
        @if ($this->canRun)
            <flux:button.group>
                @if ($workflow->hasUnpublishedChanges())
                    <flux:tooltip :content="__('workflows.edit.runs_the_published_version_not')">
                        <flux:button size="sm" icon="play" wire:click="startRun" data-test="run-button">{{ __('common.run') }}</flux:button>
                    </flux:tooltip>
                @else
                    <flux:button size="sm" icon="play" wire:click="startRun" data-test="run-button">{{ __('common.run') }}</flux:button>
                @endif
                @if ($this->canTest)
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" icon="chevron-down" :aria-label="__('workflows.edit.more_ways_to_run')" data-test="run-menu" />
                        <flux:menu>
                            <flux:menu.item icon="play" wire:click="startRun">{{ __('workflows.edit.run_published_version', ['n' => $workflow->publishedVersion->number]) }}</flux:menu.item>
                            <flux:menu.item icon="beaker" wire:click="startTestRun" data-test="test-draft">{{ __('workflows.edit.test_draft') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </flux:button.group>
        @elseif ($this->canTest)
            <flux:button size="sm" icon="beaker" wire:click="startTestRun" data-test="test-draft">{{ __('workflows.edit.test_draft') }}</flux:button>
        @endif
        @if ($this->canPublish)
            <flux:button size="sm" variant="primary" icon="rocket-launch" wire:click="publish" data-test="publish-button">{{ __('workflows.edit.publish') }}</flux:button>
        @endif
    </div>
    <flux:error name="run" class="mx-4 mt-2" />

    @if ($testErrors)
        <flux:callout variant="danger" icon="exclamation-triangle" class="m-3" :heading="__('workflows.edit.fix_these_before_testing')" data-test="test-errors">
            <ul class="list-disc ps-5 text-sm">
                @foreach ($testErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    @if ($publishErrors)
        <flux:callout variant="danger" icon="exclamation-triangle" class="m-3" :heading="__('workflows.edit.fix_these_before_publishing')">
            <ul class="list-disc ps-5 text-sm">
                @foreach ($publishErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    <div class="relative flex min-h-0 flex-1">
        {{-- The settings panel's toggle stays in the same corner whether the panel is open or closed --}}
        <flux:button size="sm" variant="ghost" icon="panel-right" class="absolute! end-2 top-2 z-20" x-on:click="$store.editorLayout.toggleSettings()" data-test="settings-panel-toggle"
            x-bind:aria-label="$store.editorLayout.settings ? @js(__('workflows.edit.hide_settings_panel')) : @js(__('workflows.edit.show_settings_panel'))"
            x-bind:title="$store.editorLayout.settings ? @js(__('workflows.edit.hide_settings_panel')) : @js(__('workflows.edit.show_settings_panel'))" />

        {{-- Palette --}}
        @if ($this->canEdit)
            {{-- Two levels: the groups, then the nodes of the open group. Searching lists matching nodes from every group. --}}
            <aside class="w-56 shrink-0 overflow-y-auto border-e border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="palette"
                x-data="{
                    folder: '',
                    query: '',
                    matches(text) { return text.toLowerCase().includes(this.query.trim().toLowerCase()) },
                    anyMatches(texts) { return texts.some(text => this.matches(text)) },
                    up() { this.folder = this.folder.split(' › ').slice(0, -1).join(' › ') },
                }"
                x-on:keydown.escape="query ? (query = '') : up()">
                <flux:input size="sm" icon="magnifying-glass" x-model="query" :placeholder="__('workflows.edit.search_nodes')" clearable class="mb-3" data-test="palette-search" />

                {{-- Back up one level, named after the open folder --}}
                <button type="button" x-show="! query && folder !== ''" x-cloak x-on:click="up()"
                    class="mb-2 flex w-full items-center gap-1 rounded-md px-2 py-1.5 text-start text-xs font-medium uppercase tracking-wide text-zinc-600 hover:bg-zinc-200/70 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    data-test="palette-back">
                    <flux:icon.chevron-left variant="micro" class="size-3.5" />
                    <span x-text="folder.split(' › ').pop()"></span>
                </button>

                {{-- The open folder's nodes, then the groups inside it; while searching, every match under its folder's path --}}
                @foreach ($this->palette as $key => $folder)
                    <div wire:key="palette-folder-{{ $loop->index }}" @if ($key !== '') x-cloak @endif
                        x-show="query ? anyMatches(@js(array_map(fn (array $item): string => $item['label'].' '.$item['description'], $folder['items']))) : folder === @js($key)"
                        data-test="palette-folder" data-folder="{{ $key }}">
                        <flux:heading size="sm" x-show="query" class="mt-3 mb-1 text-xs uppercase tracking-wide text-zinc-600 dark:text-zinc-400">{{ $key }}</flux:heading>
                        @foreach ($folder['items'] as $item)
                            <button type="button" wire:key="palette-{{ $item['type'] }}" wire:click="addNode('{{ $item['type'] }}')" title="{{ $item['description'] }}"
                                x-show="! query || matches(@js($item['label'].' '.$item['description']))"
                                x-flow-draggable="@js(['type' => $item['type']])"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-zinc-200/70 dark:hover:bg-zinc-800"
                                data-test="palette-{{ $item['type'] }}">
                                <flux:icon :icon="$item['icon']" variant="mini" class="size-4 text-zinc-500" />
                                <span>{{ $item['label'] }}</span>
                            </button>
                        @endforeach
                        @foreach ($folder['groups'] as $group)
                            <button type="button" wire:key="palette-group-{{ $group['key'] }}" x-show="! query" x-on:click="folder = @js($group['key'])"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-zinc-200/70 dark:hover:bg-zinc-800"
                                data-test="palette-group">
                                <flux:icon :icon="$group['icon']" variant="mini" class="size-4 text-zinc-500" />
                                <span class="flex-1 truncate">{{ $group['name'] }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $group['count'] }}</span>
                                <flux:icon.chevron-right variant="micro" class="size-3.5 text-zinc-400" />
                            </button>
                        @endforeach
                    </div>
                @endforeach
                <flux:text x-show="query && ! anyMatches(@js(collect($this->palette)->pluck('items')->flatten(1)->map(fn (array $item): string => $item['label'].' '.$item['description'])->all()))" x-cloak class="px-2 text-xs">
                    {{ __('workflows.edit.no_matching_nodes') }}
                </flux:text>

                <flux:text class="mt-4 text-xs">{{ __('workflows.edit.click_or_drag_to_add') }}</flux:text>
            </aside>
        @endif

        {{-- Canvas --}}
        <div class="relative min-w-0 flex-1" x-data="{ showMinimap: localStorage.getItem('wag.minimap') === 'shown' }"
            x-init="$watch('showMinimap', value => localStorage.setItem('wag.minimap', value ? 'shown' : 'hidden'))"
            :class="{ 'wag-minimap-hidden': ! showMinimap }">
            <button type="button" class="wag-minimap-toggle" x-on:click="showMinimap = ! showMinimap" data-test="minimap-toggle"
                :title="showMinimap ? @js(__('workflows.edit.hide_minimap')) : @js(__('workflows.edit.show_minimap'))"
                :aria-label="showMinimap ? @js(__('workflows.edit.hide_minimap')) : @js(__('workflows.edit.show_minimap'))"
                :aria-pressed="showMinimap.toString()">
<flux:icon.x-mark variant="micro" class="size-3.5" x-show="showMinimap" />
                <flux:icon.map variant="mini" class="size-4" x-show="! showMinimap" x-cloak />
            </button>
            <x-flow
                :nodes="$this->canvas['nodes']"
                :edges="$this->canvas['edges']"
                :controls="true"
                :minimap="true"
                :fit-view-on-init="true"
                :edges-reconnectable="false"
                :default-edge-type="\App\Engine\Editor\CanvasMapper::EDGE_TYPE"
                :config="$this->canvasConfig"
                background="dots"
                container-height="fill"
                class="h-full"
                @connect-validate="canConnect"
                @connect="onConnect"
                @node-click="selectNode"
                @pane-click="deselect"
                @node-drag-end="moveNode"
                @nodes-change="nodesChanged"
                @edges-change="edgesChanged"
            >
                <x-slot:node>
                    @include('partials.workflow-canvas-node')
                </x-slot:node>

                <div x-data="wagEdgeClasses" class="hidden" data-test="edge-classes"></div>

                @if ($this->canEdit)
                    <x-flow-context-menu scope="node" class="wag-menu" x-on:pointerdown.stop x-on:mousedown.stop>
                        <button type="button" class="wag-menu-item" data-test="node-menu-copy"
                            @click="$wire.copyNodes(selectedNodes.has(contextMenu.node.id) ? [...selectedNodes] : [contextMenu.node.id]); closeContextMenu()">
                            <flux:icon.document-duplicate variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.copy') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.copy_keys') }}</kbd>
                        </button>
                        <button type="button" class="wag-menu-item" data-test="node-menu-cut"
                            @click="$wire.cutNodes(selectedNodes.has(contextMenu.node.id) ? [...selectedNodes] : [contextMenu.node.id]); closeContextMenu()">
                            <flux:icon.scissors variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.cut') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.cut_keys') }}</kbd>
                        </button>
                        <button type="button" class="wag-menu-item is-danger" data-test="node-menu-delete"
                            @click="const ids = selectedNodes.has(contextMenu.node.id) ? [...selectedNodes] : [contextMenu.node.id]; closeContextMenu(); confirmNodeDelete(ids.length).then((ok) => ok && removeNodes(ids))">
                            <flux:icon.trash variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.delete') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.delete_keys') }}</kbd>
                        </button>
                        <div class="wag-menu-separator" role="separator"></div>
                        <button type="button" class="wag-menu-item" data-test="node-menu-settings"
                            @click="$wire.selectNode(contextMenu.node.id); $store.editorLayout.showSettingsForNode(); closeContextMenu()">
                            <flux:icon.adjustments-horizontal variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.node_settings') }}</span>
                        </button>
                    </x-flow-context-menu>

                    <x-flow-context-menu scope="selection" class="wag-menu" x-on:pointerdown.stop x-on:mousedown.stop>
                        <button type="button" class="wag-menu-item" @click="$wire.copyNodes([...selectedNodes]); closeContextMenu()">
                            <flux:icon.document-duplicate variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.copy') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.copy_keys') }}</kbd>
                        </button>
                        <button type="button" class="wag-menu-item" @click="$wire.cutNodes([...selectedNodes]); closeContextMenu()">
                            <flux:icon.scissors variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.cut') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.cut_keys') }}</kbd>
                        </button>
                        <button type="button" class="wag-menu-item is-danger" @click="const nodes = [...selectedNodes], edges = [...selectedEdges]; closeContextMenu(); (nodes.length ? confirmNodeDelete(nodes.length) : Promise.resolve(true)).then((ok) => { if (ok) { removeNodes(nodes); removeEdges(edges) } })">
                            <flux:icon.trash variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.delete') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.delete_keys') }}</kbd>
                        </button>
                    </x-flow-context-menu>

                    <x-flow-context-menu scope="pane" class="wag-menu" x-on:pointerdown.stop x-on:mousedown.stop>
                        <button type="button" class="wag-menu-item" data-test="pane-menu-paste" :disabled="! $wire.hasClipboard"
                            @click="$wire.pasteNodes(contextMenu.position); closeContextMenu()">
                            <flux:icon.clipboard-document variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.paste_here') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.paste_keys') }}</kbd>
                        </button>
                        <button type="button" class="wag-menu-item" :disabled="! $wire.canUndo" @click="$wire.undo(); closeContextMenu()">
                            <flux:icon.arrow-uturn-left variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.undo') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.undo_keys') }}</kbd>
                        </button>
                        <button type="button" class="wag-menu-item" :disabled="! $wire.canRedo" @click="$wire.redo(); closeContextMenu()">
                            <flux:icon.arrow-uturn-right variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.redo') }}</span>
                            <kbd class="wag-kbd">{{ __('workflows.edit.redo_keys') }}</kbd>
                        </button>
                        <div class="wag-menu-separator" role="separator"></div>
                        <button type="button" class="wag-menu-item" data-test="pane-menu-add-note"
                            @click="$wire.addNode('core.note', contextMenu.position); closeContextMenu()">
                            <flux:icon.pencil-square variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.add_sticky_note') }}</span>
                        </button>
                        <button type="button" class="wag-menu-item" data-test="pane-menu-workflow-settings"
                            @click="closeContextMenu(); $wire.deselect().then(() => $store.editorLayout.showSettingsForNode())">
                            <flux:icon.adjustments-horizontal variant="micro" />
                            <span class="flex-1">{{ __('workflows.edit.workflow_settings') }}</span>
                        </button>
                    </x-flow-context-menu>

                    <x-flow-context-menu scope="edge" class="wag-menu" x-on:pointerdown.stop x-on:mousedown.stop>
                        <button type="button" class="wag-menu-item is-danger" @click="removeEdges([contextMenu.edge.id]); closeContextMenu()" data-test="edge-menu-delete">
                            <flux:icon.trash variant="micro" />
                            {{ __('workflows.edit.delete_connection') }}
                        </button>
                    </x-flow-context-menu>

                    <div x-data="wagEdgeTrash" class="pointer-events-none absolute inset-0 z-20" data-test="edge-trash">
                        <template x-for="button in buttons" :key="button.id">
                            <button type="button" class="wag-edge-trash" :style="`left: ${button.left}px; top: ${button.top}px`"
                                x-on:pointerdown.stop x-on:mousedown.stop x-on:click.stop="remove(button.id)"
                                title="{{ __('workflows.edit.delete_connection') }}" aria-label="{{ __('workflows.edit.delete_connection') }}">
                                <flux:icon.trash variant="micro" />
                            </button>
                        </template>
                    </div>
                @endif
            </x-flow>
        </div>

        {{-- Inspector --}}
        <aside class="w-96 shrink-0 overflow-x-hidden overflow-y-auto border-s border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="inspector" x-show="$store.editorLayout.settings" x-data="{ inspectorTab: 'settings' }">
            @if ($this->selectedNode() && $this->definition?->kind() === NodeKind::Note)
                @php($definition = $this->definition)
                <div class="mb-4 flex items-start justify-between gap-2 pe-8" data-test="note-inspector">
                    <div>
                        <flux:heading>{{ $definition->label() }}</flux:heading>
                        <flux:text class="text-xs">{{ $definition->description() }}</flux:text>
                    </div>
                </div>
                <fieldset class="min-w-0 space-y-4" @disabled(! $this->canEdit)>
                    <flux:textarea wire:model.live.debounce.600ms="form.text" :label="__('nodes.note.text')" :description="__('nodes.note.markdown_works')" rows="8" data-test="note-text" />
                    {{-- A preset, or Custom with a color picker and a hex code --}}
                    <div class="space-y-2" x-data="{ color: $wire.entangle('form.color').live, presets: @js(\App\Engine\Nodes\Note::COLORS), get preset() { return this.presets.includes(this.color) ? this.color : 'custom' } }">
                        <flux:select :label="__('nodes.note.color')" x-bind:value="preset" data-test="note-color"
                            x-on:change="color = $event.target.value === 'custom' ? (presets.includes(color) ? '#fde68a' : color) : $event.target.value">
                            @foreach (\App\Engine\Nodes\Note::COLORS as $color)
                                <flux:select.option :value="$color">{{ __("nodes.note.{$color}") }}</flux:select.option>
                            @endforeach
                            <flux:select.option value="custom">{{ __('nodes.note.custom') }}</flux:select.option>
                        </flux:select>
                        <div class="flex items-center gap-2" x-show="preset === 'custom'" x-cloak>
                            <input type="color" class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-zinc-200 bg-transparent p-1 dark:border-white/10"
                                x-bind:value="/^#[0-9a-f]{6}$/i.test(color) ? color : '#fde68a'" x-on:change="color = $event.target.value" aria-label="{{ __('nodes.note.color') }}" />
                            <flux:input class="font-mono" x-bind:value="color" x-on:change="color = $event.target.value.trim()" :aria-label="__('nodes.note.hex_code')" placeholder="#fde68a" data-test="note-hex" />
                        </div>
                    </div>
                    <flux:select wire:model.live="form.opacity" :label="__('nodes.note.opacity')" data-test="note-opacity">
                        @foreach (\App\Engine\Nodes\Note::OPACITIES as $percent)
                            <flux:select.option :value="$percent">{{ $percent }}%</flux:select.option>
                        @endforeach
                    </flux:select>
                </fieldset>
            @elseif ($this->selectedNode() && $this->definition)
                @php($definition = $this->definition)
                <div class="mb-4 flex items-start justify-between gap-2 pe-8">
                    <div>
                        <flux:heading data-test="inspector-heading">{{ $definition->labelFor($this->selectedNode()->config) }}</flux:heading>
                        <flux:text class="text-xs">{{ $definition->description() }}</flux:text>
                    </div>
                </div>

                @if ($connectionResult)
                    <flux:callout class="mb-4" :variant="$connectionResult['variant']" :icon="$connectionResult['variant'] === 'danger' ? 'exclamation-triangle' : 'check-circle'"
                        :heading="$connectionResult['text']" data-test="connection-result" />
                @endif

                <fieldset class="min-w-0 space-y-4" @disabled(! $this->canEdit)>
                    <flux:input wire:model.live.debounce.600ms="nodeName" :label="__('common.name')" />
                    @if ($definition->kind() === NodeKind::Trigger)
                        <flux:text class="text-xs" data-test="trigger-reference">{{ __('workflows.edit.reference_its_data_as') }} <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">trigger</code></flux:text>
                    @elseif ($editingNodeId)
                        <form wire:submit="saveNodeId" class="space-y-2" data-test="node-id-form">
                            <flux:input class="font-mono" wire:model="newNodeId" wire:keydown.escape="cancelEditingNodeId" :label="__('workflows.edit.node_id')" maxlength="64" autofocus data-test="node-id-input" />
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="cancelEditingNodeId">{{ __('common.cancel') }}</flux:button>
                                <flux:button size="sm" variant="primary" type="submit" data-test="node-id-save">{{ __('common.save') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <div class="flex items-center gap-1">
                            <flux:text class="min-w-0 text-xs">{{ __('workflows.edit.reference_its_output_as') }} <code class="rounded bg-zinc-100 px-1 break-all dark:bg-zinc-800">nodes.{{ $selected }}.output</code></flux:text>
                            @if ($this->canEdit)
                                <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="startEditingNodeId" :tooltip="__('workflows.edit.change_id')" :aria-label="__('workflows.edit.change_id')" data-test="node-id-edit" />
                            @endif
                        </div>
                    @endif
                </fieldset>

                @php($formValues = [...$form, ...$this->fieldFacts()])
                @php($nodeProblems = $this->nodeProblems())
                @if ($nodeProblems)
                    <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" data-test="node-problems">
                        <flux:callout.text>
                            <ul class="list-disc ps-4">
                                @foreach ($nodeProblems as $problem)
                                    <li>{{ $problem }}</li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                {{-- The chosen tab is kept on the panel, so it stays chosen from node to node; a node without it opens on its first tab --}}
                @php($tabs = $this->inspectorTabs)
                @php($masking = in_array('masking', $tabs) ? $this->lastRunMasking : null)
                @php($tabLabels = [
                    'settings' => __('common.settings'),
                    'behavior' => __('workflows.edit.behavior'),
                    'outputs' => __('workflows.edit.outputs'),
                    'masking' => ($masking['count'] ?? 0) > 0 ? __('workflows.edit.masking.tab_with_count', ['count' => $masking['count']]) : __('workflows.edit.masking.tab'),
                ])
                <div class="mt-4" wire:key="inspector-tabs-{{ $selected }}-{{ implode('-', $tabs) }}"
                    x-data="{ tabs: @js($tabs), get tab() { return this.tabs.includes(inspectorTab) ? inspectorTab : this.tabs[0] } }">
                    <div class="mb-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:navbar scrollable class="-mb-px [scrollbar-width:none]" role="tablist" :aria-label="__('workflows.edit.node_settings')" data-test="inspector-tabs">
                            @foreach ($tabs as $tab)
                                <flux:navbar.item role="tab" id="inspector-tab-{{ $tab }}" aria-controls="inspector-panel-{{ $tab }}" x-on:click="inspectorTab = '{{ $tab }}'"
                                    x-bind:data-current="tab === '{{ $tab }}'" x-bind:aria-selected="tab === '{{ $tab }}' ? 'true' : 'false'" data-test="inspector-tab-{{ $tab }}">{{ $tabLabels[$tab] }}</flux:navbar.item>
                            @endforeach
                        </flux:navbar>
                    </div>

                    @if (in_array('settings', $tabs))
                        <div id="inspector-panel-settings" role="tabpanel" aria-labelledby="inspector-tab-settings" x-show="tab === 'settings'" data-test="inspector-panel-settings">
                            <fieldset class="min-w-0 space-y-4" @disabled(! $this->canEdit)>
                                @foreach ($definition->inputs() as $field)
                                    @continue(! $field->isVisible($formValues, $definition->inputs()))
                                    <div wire:key="field-{{ $selected }}-{{ $field->name }}">
                                        @include('partials.workflow-field', ['field' => $field->withDescription($definition->fieldHelp($field, $formValues)), 'model' => 'form.'.$field->name, 'value' => $form[$field->name] ?? null, 'values' => $formValues, 'siblings' => $definition->inputs(), 'choices' => $this->choices, 'variables' => $this->graph->variables, 'picker' => $this->picker, 'canConnectAccounts' => $this->canConnectAccounts])
                                    </div>
                                @endforeach
                            </fieldset>
                        </div>
                    @endif

                    @if (in_array('behavior', $tabs))
                        <div id="inspector-panel-behavior" role="tabpanel" aria-labelledby="inspector-tab-behavior" x-show="tab === 'behavior'" data-test="inspector-panel-behavior">
                            <fieldset class="min-w-0 space-y-4" @disabled(! $this->canEdit)>
                                @if ($definition->kind() === NodeKind::Action)
                                    <flux:checkbox wire:model.live="retryEnabled" :label="__('workflows.edit.retry_on_failure')" data-test="retry-toggle" />
                                    @if ($retryEnabled)
                                        <div class="grid grid-cols-2 gap-3" data-test="retry-settings">
                                            <flux:input type="number" min="1" max="25" wire:model.live.debounce.600ms="retry.max_retries" :label="__('workflows.edit.retries')" />
                                            <flux:input type="number" min="0" wire:model.live.debounce.600ms="retry.backoff_seconds" :label="__('workflows.edit.backoff_s')" />
                                        </div>
                                        <flux:text class="text-xs">{{ __('workflows.edit.each_retry_waits_twice_as') }}</flux:text>
                                    @endif
                                @endif

                                @if ($definition->hasErrorPort())
                                    <flux:checkbox wire:model.live="errorPath" :label="__('workflows.edit.show_on_error_path')" :description="__('workflows.edit.show_on_error_path_help')" data-test="error-path-checkbox" />
                                @endif

                                {{-- Any action can wait for its branches, except one that already does, as Merge does --}}
                                @if ($definition->kind() === NodeKind::Action && ! $definition->joinsBranches())
                                    <flux:checkbox wire:model.live="waitForAll" :label="__('workflows.edit.wait_for_all_incoming_branches')" :description="__('workflows.edit.wait_for_all_incoming_branches_help')" data-test="wait-for-all-checkbox" />
                                @endif
                            </fieldset>
                        </div>
                    @endif

                    <div id="inspector-panel-outputs" role="tabpanel" aria-labelledby="inspector-tab-outputs" x-show="tab === 'outputs'" data-test="inspector-panel-outputs">
                        <ul class="space-y-1 text-xs" data-test="node-outputs">
                            @forelse ($definition->outputs($this->selectedNode()->config) as $output)
                                <li class="wrap-anywhere"><code>{{ $output->name }}</code> <span class="text-zinc-500">· {{ $output->type }} · {{ $output->label }}</span></li>
                            @empty
                                <li class="text-zinc-500">{{ __('workflows.edit.this_node_has_no_outputs') }}</li>
                            @endforelse
                        </ul>
                    </div>

                    @if ($masking !== null)
                        <div id="inspector-panel-masking" role="tabpanel" aria-labelledby="inspector-tab-masking" x-show="tab === 'masking'" data-test="inspector-panel-masking">
                            <fieldset class="min-w-0 space-y-6" @disabled(! $this->canEdit)>
                                <flux:text class="text-xs">{{ __('workflows.edit.masking.explainer') }}</flux:text>

                                <div class="space-y-2" data-test="masked-last-run">
                                    <flux:heading size="sm">{{ __('workflows.edit.masking.masked_in_the_last_run') }}</flux:heading>
                                    @if ($masking['rows'] === [])
                                        <flux:text class="text-xs">{{ $masking['ran'] ? __('workflows.edit.masking.nothing_masked') : __('workflows.edit.masking.no_run_yet') }}</flux:text>
                                    @else
                                        <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                                            @foreach ($masking['rows'] as $index => $row)
                                                <li class="flex items-center justify-between gap-2 px-3 py-2" wire:key="masked-{{ $selected }}-{{ $row['path'] }}">
                                                    <div class="min-w-0">
                                                        <code class="text-xs break-all">{{ $row['path'] }}</code>
                                                        <flux:text class="text-xs">{{ $row['never'] ? __('workflows.edit.masking.turned_off_by_never_mask') : $row['reason'] }}</flux:text>
                                                    </div>
                                                    @if ($row['by_name'] && ! $row['never'] && $this->canEdit)
                                                        <flux:button size="xs" variant="ghost" wire:click="dontMask({{ $index }})" data-test="dont-mask">{{ __('workflows.edit.masking.dont_mask') }}</flux:button>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>

                                @foreach (['always' => __('workflows.edit.masking.always_mask'), 'never' => __('workflows.edit.masking.never_mask')] as $list => $heading)
                                    <div class="space-y-2" data-test="mask-{{ $list }}">
                                        <flux:heading size="sm">{{ $heading }}</flux:heading>
                                        @if ($this->selectedNode()->masking[$list] ?? [])
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach ($this->selectedNode()->masking[$list] as $pathIndex => $path)
                                                    <flux:badge size="sm" class="font-mono" wire:key="mask-{{ $list }}-{{ $selected }}-{{ $path }}">
                                                        {{ $path }}
                                                        @if ($this->canEdit)
                                                            <flux:badge.close wire:click="removeMaskPath('{{ $list }}', {{ $pathIndex }})" :aria-label="__('workflows.edit.masking.remove_path', ['path' => $path])" />
                                                        @endif
                                                    </flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if ($this->canEdit)
                                            <div class="flex items-start gap-2">
                                                <flux:input class="font-mono" wire:model="newMaskPath.{{ $list }}" wire:keydown.enter.prevent="addMaskPath('{{ $list }}')"
                                                    :placeholder="$list === 'always' ? 'body.items[*].key' : 'body.next_page_token'" :aria-label="$heading" data-test="mask-{{ $list }}-input" />
                                                <flux:button wire:click="addMaskPath('{{ $list }}')" data-test="mask-{{ $list }}-add">{{ __('workflows.edit.masking.add') }}</flux:button>
                                            </div>
                                            <flux:error name="newMaskPath.{{ $list }}" />
                                        @endif
                                        <flux:text class="text-xs">{{ __('workflows.edit.masking.'.$list.'_help') }}</flux:text>
                                    </div>
                                @endforeach
                            </fieldset>
                        </div>
                    @endif
                </div>
            @elseif ($this->selectedNode())
                <flux:callout variant="danger" icon="exclamation-triangle" class="mt-8">{{ __('workflows.edit.this_node_s_type_is') }}</flux:callout>
                @if ($this->canEdit)
                    <flux:button class="mt-3" size="sm" icon="trash" x-on:click="confirmNodeDelete(1).then((ok) => ok && $wire.deleteSelected())">{{ __('workflows.edit.delete_node') }}</flux:button>
                @endif
            @else
                <flux:heading class="pe-8">{{ __('workflows.edit.workflow_settings') }}</flux:heading>
                <flux:text class="mb-4 text-xs">{{ __('workflows.edit.select_a_node_to_configure') }}</flux:text>
                <form wire:submit="saveSettings" class="space-y-4">
                    <fieldset class="min-w-0 space-y-4" @disabled(! $this->canEdit)>
                        <flux:switch wire:model="enabled" :label="__('workflows.edit.enabled')" :description="__('workflows.edit.enabled_help')"
                            :disabled="! $this->canPublish" data-test="workflow-enabled" />
                        <flux:input wire:model="name" :label="__('common.name')" required />
                        <flux:textarea wire:model="description" :label="__('common.description')" rows="2" />
                        <flux:select wire:model="concurrency" :label="__('workflows.edit.concurrent_runs')">
                            @foreach (ConcurrencyPolicy::cases() as $policy)
                                <flux:select.option :value="$policy->value">{{ $policy->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:switch wire:model.live="customRetention" :label="__('workflows.edit.override_log_retention')" />
                        @if ($customRetention)
                            <flux:input type="number" min="1" wire:model="retentionDays" :label="__('workflows.edit.keep_execution_logs_for_days')" />
                        @endif
                        <flux:switch wire:model.live="customRunLimit" :label="__('workflows.edit.override_run_limit')" />
                        @if ($customRunLimit)
                            <flux:input type="number" min="1" max="10000" wire:model="maxRunsPerMinute" :label="__('workflows.edit.maximum_runs_per_minute')"
                                :description="__('workflows.edit.runs_past_it_are_cancelled')" data-test="max-runs-per-minute" />
                        @endif
                        @if ($this->canEdit)
                            <flux:button type="submit" size="sm" variant="primary">{{ __('common.save_settings') }}</flux:button>
                        @endif
                    </fieldset>
                </form>

                <flux:separator class="my-4" />
                <div data-test="workflow-variables">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading size="sm">{{ __('workflows.edit.variables') }}</flux:heading>
                        @if ($this->canEdit)
                            <flux:button size="xs" variant="subtle" icon="plus" wire:click="addVariable" data-test="add-variable">{{ __('workflows.edit.add_variable') }}</flux:button>
                        @endif
                    </div>
                    <flux:text class="mt-1 text-xs">{{ __('workflows.edit.variables_help') }}</flux:text>

                    @php($variableProblems = $this->variableProblems())
                    @if ($variableProblems)
                        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-3" data-test="variable-problems">
                            <flux:callout.text>
                                <ul class="list-disc ps-4">
                                    @foreach ($variableProblems as $variableName => $problems)
                                        @foreach ($problems as $problem)
                                            <li>{{ $variableName === '' ? $problem : __('workflows.editor.variable_name_problem', ['name' => $variableName, 'problem' => $problem]) }}</li>
                                        @endforeach
                                    @endforeach
                                </ul>
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($this->graph->variables)
                        <div class="mt-3 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @foreach ($this->graph->variables as $variable)
                                <div class="flex items-center gap-2 px-3 py-2" wire:key="variable-{{ $variable->name }}" data-test="variable-row">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-baseline gap-2">
                                            <span class="truncate font-mono text-sm">{{ $variable->name }}</span>
                                            <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $variable->valueType()?->label() ?? $variable->type }}</span>
                                        </div>
                                        @if ($variable->value !== '')
                                            <div class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400" title="{{ \Illuminate\Support\Str::limit($variable->value, 500) }}">{{ \Illuminate\Support\Str::limit($variable->value, 80) }}</div>
                                        @endif
                                    </div>
                                    @if ($this->canEdit)
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editVariable('{{ $variable->name }}')" :aria-label="__('common.edit')" data-test="edit-variable" />
                                        <flux:button size="xs" variant="ghost" icon="trash" data-test="delete-variable" :aria-label="__('common.delete')"
                                            x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workflows.edit.delete_variable'), 'message' => __('workflows.edit.delete_variable_message', ['name' => $variable->name]), 'confirm' => __('common.delete'), 'danger' => true]) }}).then((ok) => ok && $wire.deleteVariable({{ \Illuminate\Support\Js::from($variable->name) }}))" />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <flux:separator class="my-4" />
                <flux:heading size="sm">{{ __('workflows.edit.trigger_urls') }}</flux:heading>
                @forelse ($workflow->triggers()->whereIn('type', ['webhook', 'form'])->get() as $trigger)
                    <div class="mt-2" wire:key="trigger-{{ $trigger->id }}">
                        <flux:text class="text-xs">{{ $this->graph->has($trigger->node_id) ? $this->graph->node($trigger->node_id)->name : $trigger->node_id }} ({{ $trigger->type }})</flux:text>
                        <flux:input size="sm" :value="$trigger->url()" readonly copyable />
                    </div>
                @empty
                    <flux:text class="mt-1 text-xs">{{ __('workflows.edit.webhook_and_form_triggers_get') }}</flux:text>
                @endforelse

                @foreach ($workflow->triggers()->whereIn('type', [\App\Models\WorkflowTrigger::POLL, \App\Models\WorkflowTrigger::SUBSCRIPTION])->get() as $trigger)
                    <div class="mt-3" wire:key="event-trigger-{{ $trigger->id }}" data-test="event-trigger-status">
                        <flux:text class="text-xs font-medium">{{ $this->graph->has($trigger->node_id) ? $this->graph->node($trigger->node_id)->name : $trigger->node_id }}</flux:text>
                        @if (filled($trigger->state['error'] ?? null))
                            <flux:text class="text-xs text-red-600 dark:text-red-400">{{ __('workflows.edit.event_trigger_error', ['message' => $trigger->state['error']]) }}</flux:text>
                        @elseif ($trigger->type === \App\Models\WorkflowTrigger::SUBSCRIPTION)
                            <flux:text class="text-xs">{{ isset($trigger->state['subscription']) ? __('workflows.edit.webhook_registered') : __('workflows.edit.webhook_not_registered_yet') }}</flux:text>
                        @else
                            <flux:text class="text-xs">{{ filled($trigger->state['checked_at'] ?? null)
                                ? __('workflows.edit.last_checked', ['ago' => \Illuminate\Support\Carbon::parse($trigger->state['checked_at'])->diffForHumans()])
                                : __('workflows.edit.first_check_soon') }}</flux:text>
                        @endif
                    </div>
                @endforeach
            @endif
        </aside>
    </div>

    <flux:modal name="variable" class="md:w-lg">
        <form wire:submit="saveVariable" class="space-y-6">
            <flux:heading size="lg">{{ $editingVariable === null ? __('workflows.edit.add_variable') : __('workflows.edit.edit_variable') }}</flux:heading>
            <flux:input wire:model="variableForm.name" :label="__('common.name')" class="font-mono" placeholder="attempts" :disabled="$editingVariable !== null"
                :description="$editingVariable === null ? __('workflows.edit.variable_name_help') : __('workflows.edit.variable_name_fixed')" />
            <flux:select wire:model="variableForm.type" :label="__('common.type')">
                @foreach (ValueType::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div x-data="wagInsert('previewStartingValue')" class="wag-expr relative" data-test="starting-value-field">
                <flux:textarea x-ref="input" wire:model="variableForm.value" :label="__('workflows.edit.starting_value')" class="font-mono" rows="3" :description="__('workflows.edit.starting_value_help')" />
                <div wire:ignore x-ref="highlight" class="wag-expr-layer" aria-hidden="true"></div>
                @include('partials.workflow-picker', ['picker' => $this->startingValuePicker])
                @include('partials.expression-preview')
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('common.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-variable">{{ __('common.save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="run" class="md:w-lg">
        <form wire:submit="run" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('workflows.edit.run_name', ['name' => $workflow->name]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('workflows.edit.manual_trigger_asks_for_these') }}</flux:text>
            </div>
            @include('partials.form-fields', ['fields' => $this->runFields, 'model' => 'runValues'])
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('common.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="play" data-test="start-run-button">{{ __('workflows.edit.start_run') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="test-run" class="md:w-xl">
        <form wire:submit="runTest" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('workflows.edit.test_the_draft_of_name', ['name' => $workflow->name]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('workflows.edit.a_test_run_uses_the_draft') }}</flux:text>
            </div>
            @if ($this->testTriggerName !== null)
                <div data-test="test-payload">
                    @include('partials.json-editor', ['model' => 'testPayload', 'live' => false, 'rows' => 10,
                        'label' => __('workflows.edit.sample_data_for_name', ['name' => $this->testTriggerName]),
                        'description' => __('workflows.edit.what_the_trigger_receives')])
                </div>
            @else
                @include('partials.form-fields', ['fields' => $this->testFields, 'model' => 'testValues'])
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('common.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="beaker" data-test="start-test-button">{{ __('workflows.edit.start_test_run') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="versions" class="md:w-xl">
        <flux:heading size="lg">{{ __('workflows.edit.version_history') }}</flux:heading>
        <flux:text class="mb-4">{{ __('workflows.edit.each_publish_is_a_version') }}</flux:text>
        <div class="space-y-2">
            @forelse ($this->versions as $version)
                <div wire:key="version-{{ $version->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ __('common.version_n', ['n' => $version->number]) }}</span>
                            @if ($version->id === $workflow->published_version_id)
                                <flux:badge size="sm" color="green">{{ __('workflows.edit.live') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text class="text-xs">
                            {{ $version->creator?->name ?? __('workflows.edit.unknown') }} · {{ $version->created_at->diffForHumans() }}
                            @if ($version->note) · {{ $version->note }} @endif
                        </flux:text>
                    </div>
                    @if ($this->canPublish && $version->id !== $workflow->published_version_id)
                        <flux:button size="sm" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workflows.edit.roll_back_to_version_n', ['n' => $version->number]), 'message' => __('workflows.edit.publish_version_n_again_as', ['n' => $version->number]), 'confirm' => __('workflows.edit.roll_back'), 'danger' => false]) }}).then((ok) => ok && $wire.rollback({{ $version->id }}))">
                            {{ __('workflows.edit.roll_back') }}
                        </flux:button>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('workflows.edit.not_published_yet') }}</flux:text>
            @endforelse
        </div>
    </flux:modal>
</div>
