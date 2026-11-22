<?php

use App\Engine\Editor\RunCanvas;
use App\Engine\Engine;
use App\Engine\NodeRegistry;
use App\Models\Assignment;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Workspace;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use WithWireFlow;

    public Workspace $workspace;

    public Execution $execution;

    public ?int $selectedStep = null;

    /**
     * Node statuses last sent to the canvas, so polling only redraws what changed.
     *
     * @var array<string, string>
     */
    public array $drawnStatuses = [];

    public function mount(): void
    {
        $this->execution->loadMissing(['workflow', 'version', 'triggeredBy', 'cancelledBy']);
        $this->drawnStatuses = app(RunCanvas::class)->statuses($this->steps);
    }

    /**
     * @return Collection<int, ExecutionStep>
     */
    #[Computed]
    public function steps(): Collection
    {
        return $this->execution->steps()->orderBy('id')->get();
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    #[Computed]
    public function canvas(): array
    {
        return app(RunCanvas::class)->toCanvas($this->execution, $this->steps);
    }

    #[Computed]
    public function step(): ?ExecutionStep
    {
        return $this->selectedStep ? $this->steps->firstWhere('id', $this->selectedStep) : null;
    }

    #[Computed]
    public function canCancel(): bool
    {
        return $this->execution->isInProgress() && Auth::user()->can('cancel-executions', $this->workspace);
    }

    /**
     * Names of the version's nodes, by id.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function nodeNames(): array
    {
        return array_map(fn ($node) => $node->name, $this->execution->version->toGraph()->nodes);
    }

    /**
     * Nodes of the pinned version that have no step row.
     *
     * @return list<string>
     */
    #[Computed]
    public function notRun(): array
    {
        $ran = $this->steps->pluck('node_id')->unique()->all();

        return array_values(array_diff(array_keys($this->nodeNames), $ran));
    }

    public function typeLabel(string $type): string
    {
        $registry = app(NodeRegistry::class);

        return $registry->has($type) ? $registry->make($type)->label() : $type;
    }

    public function selectNode(string $nodeId): void
    {
        $this->selectedStep = $this->steps->where('node_id', $nodeId)->last()?->id;
    }

    public function selectStep(int $stepId): void
    {
        $this->selectedStep = $this->steps->firstWhere('id', $stepId)?->id;
    }

    public function deselect(): void
    {
        $this->selectedStep = null;
    }

    public function cancel(Engine $engine): void
    {
        $this->authorize('cancel-executions', $this->workspace);

        if ($this->execution->refresh()->isInProgress()) {
            $engine->cancel($this->execution, Auth::user());
            Flux::toast(text: __('runs.show.run_cancelled'));
        }

        $this->refreshRun();
    }

    /**
     * Pick up progress while the run is in progress.
     */
    public function refreshRun(): void
    {
        $this->execution->refresh();
        unset($this->steps, $this->canvas, $this->step, $this->notRun, $this->canCancel);

        $runCanvas = app(RunCanvas::class);
        $statuses = $runCanvas->statuses($this->steps);

        foreach ($statuses as $nodeId => $status) {
            if (($this->drawnStatuses[$nodeId] ?? null) !== $status) {
                $this->flowUpdateNode($nodeId, ['data' => $runCanvas->statusData($status)]);
            }
        }

        $this->drawnStatuses = $statuses;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Assignment>
     */
    public function assignments(ExecutionStep $step)
    {
        $order = array_flip(array_values((array) ($step->state['assignment_ids'] ?? [])));

        return Assignment::query()->with('assignee')->where('execution_step_id', $step->id)->get()
            ->sortBy(fn (Assignment $assignment) => $order[$assignment->id] ?? PHP_INT_MAX)
            ->values();
    }

    public function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function render()
    {
        return $this->view()->title(__('common.run_id', ['id' => \App\Models\Execution::reference($this->execution->id)]).' · '.$this->execution->workflow->name);
    }
}; ?>

<div class="-m-6 flex h-screen flex-col lg:-m-8" @if ($execution->isInProgress()) wire:poll.2s="refreshRun" @endif>
    {{-- Top bar --}}
    <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('workspaces.executions', [$workspace, 'workflow' => $execution->workflow_id])" wire:navigate :aria-label="__('runs.show.back_to_runs')" />
        <div class="min-w-0">
            <flux:heading class="truncate">{{ __('common.run_id', ['id' => \App\Models\Execution::reference($execution->id)]) }} · {{ $execution->workflow->name }}</flux:heading>
            <flux:text class="text-xs">
                {{ $execution->version->label() }}
                · {{ __('runs.trigger_types.'.$execution->trigger_type) }}
                @if ($execution->triggeredBy) · {{ __('runs.show.by_name', ['name' => $execution->triggeredBy->name]) }} @endif
                · {{ $execution->started_at?->toDayDateTimeString() }}
                @if ($execution->durationForHumans()) · {{ $execution->durationForHumans() }} @endif
            </flux:text>
        </div>
        <flux:badge size="sm" :color="$execution->status->color()" data-test="run-status">{{ $execution->status->label() }}</flux:badge>
        @if ($execution->is_test)
            <flux:badge size="sm" color="violet" icon="beaker" data-test="test-run-badge">{{ __('runs.test_run') }}</flux:badge>
        @endif
        @if ($execution->parentStep)
            <flux:link class="text-sm" :href="route('workspaces.executions.show', [$workspace, $execution->parentStep->execution_id])" wire:navigate>
                {{ __('runs.show.started_by_run_id', ['id' => \App\Models\Execution::reference($execution->parentStep->execution_id)]) }}
            </flux:link>
        @endif

        <flux:spacer />

        <flux:button size="sm" icon="pencil-square" :href="route('workspaces.workflows.edit', [$workspace, $execution->workflow])" wire:navigate>{{ __('runs.show.open_workflow') }}</flux:button>
        @if ($this->canCancel)
            <flux:button size="sm" variant="danger" icon="stop" wire:click="cancel" data-test="cancel-run-button">{{ __('runs.show.cancel_run') }}</flux:button>
        @endif
    </div>

    @if ($execution->error)
        <flux:callout variant="danger" icon="exclamation-triangle" class="m-3" :heading="$execution->error['message'] ?? __('common.run_failed')">
            @if (isset($execution->error['node_id']))
                <flux:callout.text>{{ __('runs.show.at_node', ['node' => $this->nodeNames[$execution->error['node_id']] ?? $execution->error['node_id']]) }}</flux:callout.text>
            @endif
        </flux:callout>
    @elseif ($execution->cancelledBy)
        <flux:callout icon="stop" class="m-3" :heading="__('runs.show.cancelled_by_name', ['name' => $execution->cancelledBy->name])" />
    @endif

    <div class="flex min-h-0 flex-1">
        {{-- Canvas --}}
        <div class="relative min-w-0 flex-1">
            <x-flow
                :nodes="$this->canvas['nodes']"
                :edges="$this->canvas['edges']"
                :interactive="false"
                :controls="true"
                :minimap="false"
                :fit-view-on-init="true"
                :edges-reconnectable="false"
                default-edge-type="smoothstep"
                background="dots"
                container-height="fill"
                class="h-full"
                @node-click="selectNode"
                @pane-click="deselect"
            >
                <x-slot:node>
                    @include('partials.workflow-canvas-node')
                </x-slot:node>
            </x-flow>
        </div>

        {{-- Steps and step detail --}}
        <aside class="w-[28rem] shrink-0 overflow-y-auto border-s border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="run-inspector">
            @if ($this->step)
                @php($step = $this->step)
                <div class="mb-4 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <flux:heading class="truncate">{{ $this->nodeNames[$step->node_id] ?? $step->node_id }}</flux:heading>
                        <flux:text class="text-xs">
                            {{ $this->typeLabel($step->node_type) }} · {{ __('runs.show.attempt_n', ['n' => $step->attempt]) }}
                            @if ($iteration = \App\Engine\Editor\RunCanvas::iterationLabel($step)) · {{ $iteration }} @endif
                        </flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="deselect" :aria-label="__('runs.show.back_to_all_steps')" />
                </div>

                <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                    <flux:badge size="sm" :color="$step->status->color()">{{ $step->status->label() }}</flux:badge>
                    @if ($step->started_at)
                        <flux:text class="text-xs">{{ $step->started_at->toTimeString() }}
                            @if ($step->finished_at) · {{ $step->started_at->diffForHumans($step->finished_at, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }} @endif
                        </flux:text>
                    @endif
                    @if ($step->status === \App\Enums\StepStatus::Waiting && $step->resume_at)
                        <flux:text class="text-xs">
                            {{ $step->wait_type === \App\Enums\WaitType::Timer
                                ? __('runs.show.resumes_time', ['time' => $step->resume_at->diffForHumans()])
                                : __('runs.show.times_out_time', ['time' => $step->resume_at->toDayDateTimeString()]) }}
                        </flux:text>
                    @endif
                </div>

                @foreach ($this->assignments($step) as $assignment)
                    <flux:callout wire:key="assignment-{{ $assignment->id }}" icon="user" class="mb-2 last-of-type:mb-4" :heading="__('runs.show.type_for_name', ['type' => $assignment->type->label(), 'name' => $assignment->assignee->name])">
                        <flux:callout.text>{{ $assignment->status->label() }}@if ($assignment->due_at && $assignment->isPending()) · {{ __('common.due_time', ['time' => $assignment->due_at->diffForHumans()]) }}@endif</flux:callout.text>
                    </flux:callout>
                @endforeach

                @foreach ($step->childExecutions as $child)
                    <flux:link wire:key="child-{{ $child->id }}" class="mb-4 block text-sm" :href="route('workspaces.executions.show', [$workspace, $child])" wire:navigate>
                        {{ __('runs.show.child_run_id_status', ['id' => \App\Models\Execution::reference($child->id), 'status' => $child->status->label()]) }}
                    </flux:link>
                @endforeach

                @foreach (['error' => __('common.error'), 'input' => __('common.input'), 'output' => __('common.output')] as $part => $label)
                    @if ($step->{$part} !== null && $step->{$part} !== [])
                        <flux:heading size="sm" class="mt-4 mb-1">{{ $label }}</flux:heading>
                        <pre class="max-h-80 overflow-auto rounded-lg whitespace-pre-wrap break-words bg-zinc-50 p-3 text-xs dark:bg-zinc-800 {{ $part === 'error' ? 'text-red-600 dark:text-red-400' : '' }}" data-test="step-{{ $part }}">{{ $this->json($step->{$part}) }}</pre>
                    @endif
                @endforeach
            @else
                <flux:heading class="mb-3">{{ __('runs.show.steps') }}</flux:heading>

                @if ($execution->input)
                    <details class="mb-4">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('runs.show.trigger_input') }}</summary>
                        <pre class="mt-2 max-h-60 overflow-auto rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800">{{ $this->json($execution->input) }}</pre>
                    </details>
                @endif

                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($this->steps as $step)
                        <button type="button" wire:key="step-{{ $step->id }}" wire:click="selectStep({{ $step->id }})" data-test="step-row"
                            class="flex w-full items-center gap-2 px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $this->nodeNames[$step->node_id] ?? $step->node_id }}</span>
                                <span class="block truncate text-xs text-zinc-500">
                                    {{ $this->typeLabel($step->node_type) }}
                                    @if ($step->attempt > 1) · {{ __('runs.show.attempt_n_2', ['n' => $step->attempt]) }} @endif
                                    @if ($iteration = \App\Engine\Editor\RunCanvas::iterationLabel($step)) · {{ $iteration }} @endif
                                </span>
                            </span>
                            <flux:badge size="sm" :color="$step->status->color()">{{ $step->status->label() }}</flux:badge>
                        </button>
                    @empty
                        <div class="p-6 text-center text-sm text-zinc-500">{{ $execution->status === \App\Enums\ExecutionStatus::Skipped ? __('runs.show.skipped_because_another_run_was') : __('runs.show.no_steps_yet') }}</div>
                    @endforelse
                </div>

                @if ($this->notRun)
                    <flux:heading size="sm" class="mt-6 mb-1">{{ $execution->isInProgress() ? __('runs.show.not_reached_yet') : __('common.not_run') }}</flux:heading>
                    <flux:text class="text-sm" data-test="not-run">{{ collect($this->notRun)->map(fn ($id) => $this->nodeNames[$id])->join(', ') }}</flux:text>
                @endif

                @if ($execution->output !== null && $execution->status === \App\Enums\ExecutionStatus::Complete)
                    <flux:heading size="sm" class="mt-6 mb-1">{{ __('runs.show.run_output') }}</flux:heading>
                    <pre class="max-h-60 overflow-auto rounded-lg whitespace-pre-wrap break-words bg-zinc-50 p-3 text-xs dark:bg-zinc-800">{{ $this->json($execution->output) }}</pre>
                @endif
            @endif
        </aside>
    </div>
</div>
