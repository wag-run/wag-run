<?php

namespace App\Http\Controllers;

use App\Engine\Engine;
use App\Engine\EventTrigger;
use App\Engine\NodeRegistry;
use App\Models\WorkflowTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the webhooks event triggers registered with other apps, and
 * starts a run for each event the trigger cares about. While the workflow is
 * disabled, deliveries are accepted and dropped, so the other app keeps the
 * webhook instead of turning it off after failed deliveries.
 */
class EventWebhookController extends Controller
{
    public function __invoke(Request $request, string $key, NodeRegistry $registry, Engine $engine): JsonResponse
    {
        $trigger = WorkflowTrigger::query()
            ->where('type', WorkflowTrigger::SUBSCRIPTION)
            ->where('key', $key)
            ->with('workflow.publishedVersion')
            ->first();

        abort_unless($trigger && $trigger->workflow->publishedVersion && filled($trigger->state['secret'] ?? null), 404);

        $graph = $trigger->workflow->publishedVersion->toGraph();
        $node = $graph->has($trigger->node_id) && $registry->has($graph->node($trigger->node_id)->type)
            ? $registry->make($graph->node($trigger->node_id)->type)
            : null;

        abort_unless($node instanceof EventTrigger, 404);

        $events = $node->receive($trigger->config(), $request, (string) $trigger->state['secret']);

        if ($events === null) {
            return response()->json(['message' => __('webhooks.request_could_not_be_verified')], 403);
        }

        if (! $trigger->workflow->is_enabled) {
            return response()->json(['execution_ids' => []], 202);
        }

        $runs = array_map(
            fn (array $event) => $engine->start($trigger->workflow, $event, triggerType: 'event', triggerNodeId: $trigger->node_id)->id,
            $events,
        );

        return response()->json(['execution_ids' => $runs], 202);
    }
}
