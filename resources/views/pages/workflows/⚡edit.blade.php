<?php

use App\Engine\Definitions\Field;
use App\Engine\Editor\CanvasMapper;
use App\Engine\Editor\ConfigForm;
use App\Engine\Editor\ConnectionRules;
use App\Engine\Editor\DataPicker;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\Node;
use App\Engine\NodeRegistry;
use App\Enums\ConcurrencyPolicy;
use App\Enums\NodeKind;
use App\Models\Organization;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Services\Workflows;
use App\Support\Audit;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new class extends Component {
    use WithWireFlow;

    public Workspace $workspace;

    public Workflow $workflow;

    public ?string $selected = null;

    public string $nodeName = '';

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * @var array{max_retries: int|string, backoff_seconds: int|string}
     */
    public array $retry = ['max_retries' => 0, 'backoff_seconds' => 30];

    public string $name = '';

    public string $description = '';

    public string $concurrency = 'allow';

    public bool $customRetention = false;

    public ?int $retentionDays = null;

    /**
     * @var list<string>
     */
    public array $publishErrors = [];

    public function mount(): void
    {
        $this->name = $this->workflow->name;
        $this->description = (string) $this->workflow->description;
        $this->concurrency = $this->workflow->concurrency->value;
        $this->customRetention = $this->workflow->retention_days !== null;
        $this->retentionDays = $this->workflow->retention_days ?? $this->workspace->effectiveRetentionDays();
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
    public function canPublish(): bool
    {
        return Auth::user()->can('publish-workflows', $this->workspace);
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
     * @return array<string, list<array{type: string, label: string, description: string, icon: string}>>
     */
    #[Computed]
    public function palette(): array
    {
        $groups = [];

        foreach (app(NodeRegistry::class)->all() as $type => $node) {
            $group = $node->kind() === NodeKind::Trigger ? __('Triggers') : $node->category();
            $groups[$group][] = ['type' => $type, 'label' => $node->label(), 'description' => $node->description(), 'icon' => $node->icon()];
        }

        return $groups;
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
     * Choices for credential, secret, workflow and user fields.
     *
     * @return array<string, array<string, string>>
     */
    #[Computed]
    public function choices(): array
    {
        $credentials = $this->workspace->credentials()->orderBy('key')->get(['key', 'type']);

        return [
            'credentials' => $credentials->mapWithKeys(fn ($credential) => [$credential->key => $credential->type])->all(),
            'secrets' => $this->workspace->secrets()->orderBy('key')->pluck('key', 'key')->all(),
            'workflows' => $this->workspace->workflows()->orderBy('name')->pluck('name', 'id')->all(),
            'users' => Organization::current()?->users()->wherePivotNull('deactivated_at')->orderBy('name')->get(['users.id', 'name', 'email'])
                ->mapWithKeys(fn ($user) => [$user->id => "{$user->name} ({$user->email})"])->all() ?? [],
        ];
    }

    #[Computed]
    public function versions()
    {
        return $this->workflow->versions()->with('publisher')->orderByDesc('number')->get();
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
        $this->form = $this->definition ? ConfigForm::toForm($this->definition, $node->config) : [];
        $this->resetValidation();
    }

    public function deselect(): void
    {
        $this->selected = null;
        $this->form = [];
    }

    /**
     * @return bool|array{allowed: false, reason: string}
     */
    public function canConnect(string $source, string $target, ?string $sourceHandle = null, ?string $targetHandle = null, ?string $replacing = null): bool|array
    {
        if (! $this->canEdit) {
            return ['allowed' => false, 'reason' => __('You can view this workflow but not edit it.')];
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
        ])));
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

        $this->save($graph);
    }

    public function addNode(string $type): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $registry = app(NodeRegistry::class);
        abort_unless($registry->has($type), 404);

        $definition = $registry->make($type);
        $graph = $this->graph;
        $anchor = $this->selectedNode();
        $siblings = $anchor ? count($graph->outgoing($anchor->id)) : 0;
        $position = $anchor
            ? ['x' => $anchor->position['x'] + 300, 'y' => $anchor->position['y'] + ($siblings * 140)]
            : ['x' => (collect($graph->nodes)->max(fn ($node) => $node->position['x']) ?? -220) + 300, 'y' => 160];

        $id = $graph->freeId(str($type)->afterLast('.')->replace('_trigger', '')->toString());
        $config = collect($definition->inputs())->filter(fn (Field $field) => $field->default !== null)->mapWithKeys(fn (Field $field) => [$field->name => $field->default])->all();
        $node = new GraphNode($id, $type, $definition->label(), $config, position: $position);

        $graph = $graph->withNode($node);

        $port = $anchor ? $this->firstPort($anchor) : null;

        if ($anchor && $port && app(ConnectionRules::class)->check($graph, $anchor->id, $port, $id) === null) {
            $graph = app(ConnectionRules::class)->connect($graph, $anchor->id, $port, $id);
        }

        $this->save($graph);
        $this->reloadCanvas();
        $this->selectNode($id);
        $this->flowSelectNodes([$id]);
        $this->flowSetCenter($position['x'] + 110, $position['y'] + 40);
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
        unset($this->form[$field][$index]);
        $this->form[$field] = array_values($this->form[$field] ?? []);

        $this->saveSelected();
    }

    public function saveSettings(): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'concurrency' => ['required', \Illuminate\Validation\Rule::enum(ConcurrencyPolicy::class)],
            'retentionDays' => [$this->customRetention ? 'required' : 'nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $this->workflow->fill([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'concurrency' => $this->concurrency,
            'retention_days' => $this->customRetention ? $this->retentionDays : null,
        ]);

        if ($this->workflow->isDirty()) {
            $changed = array_keys($this->workflow->getDirty());
            $this->workflow->save();
            Audit::record('workflow.settings_updated', $this->workflow, $this->workspace, ['changed' => $changed]);
        }

        Flux::toast(variant: 'success', text: __('Workflow settings saved.'));
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
        Flux::toast(variant: 'success', text: __('Published version :n. New runs use it.', ['n' => $version->number]));
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
        Flux::toast(variant: 'success', text: __('Rolled back to version :old as version :new.', ['old' => $target->number, 'new' => $version->number]));
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

        $config = ConfigForm::toConfig($this->definition, $this->form);
        $retry = (int) $this->retry['max_retries'] > 0
            ? ['max_retries' => (int) $this->retry['max_retries'], 'backoff_seconds' => (int) ($this->retry['backoff_seconds'] ?? 30)]
            : null;

        $oldPorts = $this->definition->ports($node->config);
        $newPorts = $this->definition->ports($config);
        $updated = new GraphNode($node->id, $node->type, $this->nodeName, $config, $retry, $node->position);

        $graph = $this->graph->withNode($updated);

        if ($oldPorts !== $newPorts) {
            $graph = $graph->withoutEdgesFromPortsOtherThan($node->id, [...$newPorts, 'error']);
        }

        $this->save($graph);

        if ($oldPorts !== $newPorts) {
            $this->reloadCanvas();
        } else {
            $this->flowUpdateNode($node->id, ['data' => $this->withIcon(app(CanvasMapper::class)->node($updated))['data']]);
        }
    }

    protected function save(WorkflowGraph $graph): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        app(Workflows::class)->saveDraft($this->workflow, $graph);
        unset($this->graph, $this->canvas);
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

<div class="-m-6 flex h-screen flex-col lg:-m-8">
    {{-- Top bar --}}
    <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('workspaces.workflows', $workspace)" wire:navigate :aria-label="__('Back to workflows')" />
        <div class="min-w-0">
            <flux:heading class="truncate">{{ $workflow->name }}</flux:heading>
        </div>
        @if ($workflow->publishedVersion)
            <flux:badge size="sm" color="green">{{ __('Published v:n', ['n' => $workflow->publishedVersion->number]) }}</flux:badge>
        @endif
        @if ($workflow->hasUnpublishedChanges())
            <flux:badge size="sm" color="amber" data-test="draft-badge">{{ __('Unpublished draft') }}</flux:badge>
        @endif
        <flux:text class="text-xs" wire:loading.remove wire:target="moveNode,onConnect,nodesChanged,edgesChanged,form,nodeName,retry,addNode">
            {{ $workflow->draft_updated_at ? __('Draft saved :time', ['time' => $workflow->draft_updated_at->diffForHumans()]) : '' }}
        </flux:text>
        <flux:text class="text-xs" wire:loading wire:target="moveNode,onConnect,nodesChanged,edgesChanged,form,nodeName,retry,addNode">{{ __('Saving…') }}</flux:text>

        <flux:spacer />

        <flux:modal.trigger name="versions">
            <flux:button size="sm" icon="clock">{{ __('Versions') }}</flux:button>
        </flux:modal.trigger>
        <flux:button size="sm" icon="arrow-down-tray" :href="route('workspaces.workflows.export', [$workspace, $workflow])">{{ __('Export') }}</flux:button>
        @if ($this->canPublish)
            <flux:button size="sm" variant="primary" icon="rocket-launch" wire:click="publish" data-test="publish-button">{{ __('Publish') }}</flux:button>
        @endif
    </div>

    @if ($publishErrors)
        <flux:callout variant="danger" icon="exclamation-triangle" class="m-3" :heading="__('Fix these before publishing')">
            <ul class="list-disc ps-5 text-sm">
                @foreach ($publishErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    <div class="flex min-h-0 flex-1">
        {{-- Palette --}}
        @if ($this->canEdit)
            <aside class="w-56 shrink-0 overflow-y-auto border-e border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900">
                @foreach ($this->palette as $group => $items)
                    <flux:heading size="sm" class="mt-3 mb-1 first:mt-0 text-xs uppercase tracking-wide text-zinc-500">{{ $group }}</flux:heading>
                    @foreach ($items as $item)
                        <button type="button" wire:key="palette-{{ $item['type'] }}" wire:click="addNode('{{ $item['type'] }}')" title="{{ $item['description'] }}"
                            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-zinc-200/70 dark:hover:bg-zinc-800"
                            data-test="palette-{{ $item['type'] }}">
                            <flux:icon :icon="$item['icon']" variant="mini" class="size-4 text-zinc-500" />
                            <span>{{ $item['label'] }}</span>
                        </button>
                    @endforeach
                @endforeach
            </aside>
        @endif

        {{-- Canvas --}}
        <div class="relative min-w-0 flex-1">
            <x-flow
                :nodes="$this->canvas['nodes']"
                :edges="$this->canvas['edges']"
                :interactive="$this->canEdit"
                :controls="true"
                :minimap="true"
                :fit-view-on-init="true"
                :edges-reconnectable="false"
                default-edge-type="smoothstep"
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
                    <div class="wag-node" :class="{ 'is-trigger': node.data.isTrigger, 'is-unknown': node.data.unknown }">
                        <template x-if="! node.data.isTrigger">
                            <div x-flow-handle:target.left="'in'"></div>
                        </template>
                        <div class="wag-node-header">
                            <span class="wag-node-icon" x-html="node.data.iconHtml"></span>
                            <div class="min-w-0">
                                <div class="truncate font-medium" x-text="node.data.name"></div>
                                <div class="truncate text-xs text-zinc-500" x-text="node.data.typeLabel + ' · ' + node.id"></div>
                            </div>
                        </div>
                        <div class="py-1">
                            <template x-for="port in node.data.ports" :key="port.id">
                                <div class="wag-port">
                                    <span x-text="port.label"></span>
                                    <div x-flow-handle:source.right="port.id"></div>
                                </div>
                            </template>
                            <template x-if="node.data.hasErrorPort">
                                <div class="wag-port wag-port-error">
                                    <span>{{ __('on error') }}</span>
                                    <div x-flow-handle:source.right="'error'" class="wag-handle-error"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </x-slot:node>
            </x-flow>
        </div>

        {{-- Inspector --}}
        <aside class="w-96 shrink-0 overflow-y-auto border-s border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="inspector">
            @if ($this->selectedNode() && $this->definition)
                @php($definition = $this->definition)
                <div class="mb-4 flex items-start justify-between gap-2">
                    <div>
                        <flux:heading>{{ $definition->label() }}</flux:heading>
                        <flux:text class="text-xs">{{ $definition->description() }}</flux:text>
                    </div>
                    @if ($this->canEdit)
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteSelected" :aria-label="__('Delete node')" />
                    @endif
                </div>

                <fieldset class="space-y-4" @disabled(! $this->canEdit)>
                    <flux:input wire:model.live.debounce.600ms="nodeName" :label="__('Name')" />
                    <flux:text class="text-xs">{{ __('Reference its output as') }} <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">nodes.{{ $selected }}.output</code></flux:text>

                    @foreach ($definition->inputs() as $field)
                        <div wire:key="field-{{ $selected }}-{{ $field->name }}">
                            @include('partials.workflow-field', ['field' => $field, 'model' => 'form.'.$field->name, 'value' => $form[$field->name] ?? null, 'choices' => $this->choices, 'picker' => $this->picker])
                        </div>
                    @endforeach

                    @if ($definition->kind() === NodeKind::Action)
                        <flux:separator />
                        <flux:heading size="sm">{{ __('Retries') }}</flux:heading>
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input type="number" min="0" max="25" wire:model.live.debounce.600ms="retry.max_retries" :label="__('Retries')" />
                            <flux:input type="number" min="0" wire:model.live.debounce.600ms="retry.backoff_seconds" :label="__('Backoff (s)')" />
                        </div>
                        <flux:text class="text-xs">{{ __('Each retry waits twice as long as the one before. Connect the "on error" output to handle a final failure.') }}</flux:text>
                    @endif
                </fieldset>

                <flux:separator class="my-4" />
                <flux:heading size="sm">{{ __('Outputs') }}</flux:heading>
                <ul class="mt-2 space-y-1 text-xs">
                    @forelse ($definition->outputs($this->selectedNode()->config) as $output)
                        <li><code>{{ $output->name }}</code> <span class="text-zinc-500">· {{ $output->type }} · {{ $output->label }}</span></li>
                    @empty
                        <li class="text-zinc-500">{{ __('This node has no outputs.') }}</li>
                    @endforelse
                </ul>
            @elseif ($this->selectedNode())
                <flux:callout variant="danger" icon="exclamation-triangle">{{ __('This node\'s type is not installed. Delete it or install its integration.') }}</flux:callout>
                @if ($this->canEdit)
                    <flux:button class="mt-3" size="sm" icon="trash" wire:click="deleteSelected">{{ __('Delete node') }}</flux:button>
                @endif
            @else
                <flux:heading>{{ __('Workflow settings') }}</flux:heading>
                <flux:text class="mb-4 text-xs">{{ __('Select a node to configure it. Add nodes from the left; drag from an output to an input to connect them.') }}</flux:text>
                <form wire:submit="saveSettings" class="space-y-4">
                    <fieldset class="space-y-4" @disabled(! $this->canEdit)>
                        <flux:input wire:model="name" :label="__('Name')" required />
                        <flux:textarea wire:model="description" :label="__('Description')" rows="2" />
                        <flux:select wire:model="concurrency" :label="__('Concurrent runs')">
                            @foreach (ConcurrencyPolicy::cases() as $policy)
                                <flux:select.option :value="$policy->value">{{ $policy->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:switch wire:model.live="customRetention" :label="__('Override log retention')" />
                        @if ($customRetention)
                            <flux:input type="number" min="1" wire:model="retentionDays" :label="__('Keep execution logs for (days)')" />
                        @endif
                        @if ($this->canEdit)
                            <flux:button type="submit" size="sm" variant="primary">{{ __('Save settings') }}</flux:button>
                        @endif
                    </fieldset>
                </form>

                <flux:separator class="my-4" />
                <flux:heading size="sm">{{ __('Trigger URLs') }}</flux:heading>
                @forelse ($workflow->triggers()->whereIn('type', ['webhook', 'form'])->get() as $trigger)
                    <div class="mt-2" wire:key="trigger-{{ $trigger->id }}">
                        <flux:text class="text-xs">{{ $this->graph->has($trigger->node_id) ? $this->graph->node($trigger->node_id)->name : $trigger->node_id }} ({{ $trigger->type }})</flux:text>
                        <flux:input size="sm" :value="$trigger->url()" readonly copyable />
                    </div>
                @empty
                    <flux:text class="mt-1 text-xs">{{ __('Webhook and form triggers get their URL when the workflow is published.') }}</flux:text>
                @endforelse
            @endif
        </aside>
    </div>

    <flux:modal name="versions" class="md:w-xl">
        <flux:heading size="lg">{{ __('Version history') }}</flux:heading>
        <flux:text class="mb-4">{{ __('Each publish is a version. Runs stay on the version they started with. Rolling back publishes an old version again as a new one.') }}</flux:text>
        <div class="space-y-2">
            @forelse ($this->versions as $version)
                <div wire:key="version-{{ $version->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ __('Version :n', ['n' => $version->number]) }}</span>
                            @if ($version->id === $workflow->published_version_id)
                                <flux:badge size="sm" color="green">{{ __('Live') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text class="text-xs">
                            {{ $version->publisher?->name ?? __('Unknown') }} · {{ $version->created_at->diffForHumans() }}
                            @if ($version->note) · {{ $version->note }} @endif
                        </flux:text>
                    </div>
                    @if ($this->canPublish && $version->id !== $workflow->published_version_id)
                        <flux:button size="sm" wire:click="rollback({{ $version->id }})" wire:confirm="{{ __('Publish version :n again as a new version? The current draft is replaced.', ['n' => $version->number]) }}">
                            {{ __('Roll back') }}
                        </flux:button>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('Not published yet.') }}</flux:text>
            @endforelse
        </div>
    </flux:modal>
</div>
