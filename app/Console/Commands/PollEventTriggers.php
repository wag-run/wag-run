<?php

namespace App\Console\Commands;

use App\Engine\EventTrigger;
use App\Engine\NodeRegistry;
use App\Jobs\PollTrigger;
use App\Models\WorkflowTrigger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:poll-triggers')]
#[Description('Queue a poll for every event trigger that is due')]
class PollEventTriggers extends Command
{
    public function handle(NodeRegistry $registry): int
    {
        $queued = 0;

        $due = WorkflowTrigger::query()
            ->where('type', WorkflowTrigger::POLL)
            ->where('next_run_at', '<=', now())
            ->with('workflow.publishedVersion')
            ->limit(500)
            ->get();

        foreach ($due as $trigger) {
            $config = $trigger->config();
            $graph = $trigger->workflow->publishedVersion?->toGraph();
            $node = $graph && $graph->has($trigger->node_id) && $registry->has($graph->node($trigger->node_id)->type)
                ? $registry->make($graph->node($trigger->node_id)->type)
                : null;
            $minutes = $node instanceof EventTrigger ? max(1, $node->pollEvery($config)) : 5;

            $claimed = WorkflowTrigger::query()
                ->whereKey($trigger->id)
                ->where('next_run_at', $trigger->next_run_at)
                ->update(['next_run_at' => now()->addMinutes($minutes)]);

            if ($claimed === 0) {
                continue;
            }

            PollTrigger::dispatch($trigger->id);
            $queued++;
        }

        $this->components->info("Queued {$queued} poll(s).");

        return self::SUCCESS;
    }
}
