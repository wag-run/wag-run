<?php

use App\Engine\Engine;
use App\Models\WorkflowTrigger;
use App\Services\Assignments;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component {
    #[Locked]
    public string $key = '';

    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public bool $submitted = false;

    public function mount(string $key): void
    {
        $this->key = $key;

        abort_unless($this->trigger, 404);

        if ($this->membersOnly && ! $this->canSubmit()) {
            Auth::check() ? abort(403) : $this->redirectRoute('login');
        }

        foreach ($this->fields as $field) {
            $this->values[$field['name']] = ($field['type'] ?? 'text') === 'checkbox' ? false : null;
        }
    }

    #[Computed]
    public function trigger(): ?WorkflowTrigger
    {
        return WorkflowTrigger::query()
            ->where('type', WorkflowTrigger::FORM)
            ->where('key', $this->key)
            ->whereHas('workflow', fn ($query) => $query->whereNotNull('published_version_id'))
            ->with('workflow.publishedVersion', 'workflow.workspace')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function config(): array
    {
        return $this->trigger?->config() ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function fields(): array
    {
        return array_values(array_filter((array) ($this->config['fields'] ?? []), fn ($field) => is_array($field) && filled($field['name'] ?? null)));
    }

    #[Computed]
    public function membersOnly(): bool
    {
        return ($this->config['access'] ?? 'public') === 'members';
    }

    public function canSubmit(): bool
    {
        if (! $this->membersOnly) {
            return true;
        }

        $role = Auth::user()?->roleIn($this->trigger->workflow->workspace);

        return $role !== null && $role !== \App\Enums\WorkspaceRole::Guest;
    }

    public function submit(Engine $engine): void
    {
        abort_unless($this->trigger && $this->canSubmit(), 403);

        $rules = [];
        $attributes = [];
        foreach ($this->fields as $field) {
            $rules["values.{$field['name']}"] = Assignments::rulesFor($field);
            $attributes["values.{$field['name']}"] = $field['label'] ?? $field['name'];
        }

        $validated = Assignments::castValues($this->fields, $this->validate($rules, [], $attributes)['values'] ?? []);

        $engine->start($this->trigger->workflow, [
            'values' => $validated,
            'submitted_at' => now()->toIso8601String(),
            'submitted_by' => Auth::id(),
        ], user: Auth::user(), triggerType: 'form', triggerNodeId: $this->trigger->node_id);

        $this->submitted = true;
    }

    public function render()
    {
        return $this->view()->title(__('forms.show.title'));
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="$this->config['title'] ?? __('forms.show.form')" :description="$this->config['description'] ?? ''" />

    @if ($submitted)
        <flux:callout icon="check-circle" variant="success">
            {{ $this->config['success_message'] ?? __('forms.show.thanks_your_response_was_submitted') }}
        </flux:callout>
    @else
        <form wire:submit="submit" class="flex flex-col gap-6">
            @include('partials.form-fields', ['fields' => $this->fields, 'model' => 'values'])

            <flux:button type="submit" variant="primary" class="w-full" data-test="submit-form-button">{{ __('common.submit') }}</flux:button>
        </form>
    @endif
</div>
