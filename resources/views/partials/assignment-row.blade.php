<a href="{{ route('workspaces.inbox.show', [$workspace, $assignment]) }}" wire:navigate wire:key="assignment-{{ $assignment->id }}"
   class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/40" data-test="assignment-row">
    <flux:icon :name="$assignment->type === \App\Enums\AssignmentType::Approval ? 'check-badge' : 'clipboard-document-check'" variant="mini" class="text-zinc-400" />
    <div class="min-w-0 flex-1">
        <div class="truncate font-medium">{{ $assignment->title }}</div>
        <div class="truncate text-xs text-zinc-500">
            {{ $assignment->type->label() }} · {{ $assignment->execution->workflow->name }}
            @if ($assignment->isPending() && $assignment->due_at)
                · {{ __('due :time', ['time' => $assignment->due_at->diffForHumans()]) }}
            @endif
        </div>
    </div>
    <flux:badge size="sm" :color="$assignment->status->color()">{{ $assignment->status->label() }}</flux:badge>
</a>
