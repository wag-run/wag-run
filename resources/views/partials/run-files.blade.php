{{-- Download links for the files in a run's value (a step's input or output, or the trigger input). --}}
@php($references = \App\Services\WorkflowFiles::referencesIn($value))
@if ($references !== [])
    <ul class="mt-2 space-y-1 text-sm" data-test="run-files">
        @foreach ($references as $reference)
            <li wire:key="file-{{ $key }}-{{ $reference['id'] }}" class="flex items-center gap-2">
                <flux:icon.paper-clip variant="micro" class="text-zinc-400" />
                <flux:link :href="route('workspaces.executions.files.download', [$workspace, $execution, $reference['id']])">{{ $reference['name'] }}</flux:link>
                <span class="text-xs text-zinc-500">{{ $reference['mime'] }} · {{ \Illuminate\Support\Number::fileSize((int) $reference['size']) }}</span>
            </li>
        @endforeach
    </ul>
@endif
