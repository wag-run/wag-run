<?php

namespace App\Jobs;

use App\Engine\Engine;
use App\Engine\EventTrigger;
use App\Engine\NodeRegistry;
use App\Models\WorkflowTrigger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks another app for new events for one polling trigger, and starts a run
 * for each. A failed poll is noted on the trigger, for the editor to show,
 * and tried again at the next interval. A disabled workflow is still polled,
 * so what it remembers stays current, but its events start no runs: they are
 * dropped rather than started all at once when it is enabled again.
 */
class PollTrigger implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $triggerId) {}

    public function handle(NodeRegistry $registry, Engine $engine): void
    {
        $trigger = WorkflowTrigger::query()->with('workflow.publishedVersion', 'workflow.workspace')->find($this->triggerId);

        if (! $trigger || $trigger->type !== WorkflowTrigger::POLL || ! $trigger->workflow->publishedVersion) {
            return;
        }

        $graph = $trigger->workflow->publishedVersion->toGraph();
        $node = $graph->has($trigger->node_id) && $registry->has($graph->node($trigger->node_id)->type)
            ? $registry->make($graph->node($trigger->node_id)->type)
            : null;

        if (! $node instanceof EventTrigger) {
            return;
        }

        $state = $trigger->state ?? [];
        $memory = $state['memory'] ?? null;

        try {
            $result = $node->poll($trigger->config(), $trigger->workflow->workspace, $memory);
        } catch (Throwable $e) {
            $trigger->forceFill(['state' => [...$state, 'error' => $e->getMessage(), 'checked_at' => now()->toIso8601String()]])->save();

            return;
        }

        $trigger->forceFill(['state' => [...$state, 'memory' => $result['state'], 'error' => null, 'checked_at' => now()->toIso8601String()]])->save();

        if (! $trigger->workflow->is_enabled) {
            return;
        }

        foreach ($result['events'] as $event) {
            $engine->start($trigger->workflow, $event, triggerType: 'event', triggerNodeId: $trigger->node_id);
        }
    }
}
