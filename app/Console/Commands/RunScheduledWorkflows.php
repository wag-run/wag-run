<?php

namespace App\Console\Commands;

use App\Engine\Engine;
use App\Engine\Nodes\Triggers\ScheduleTrigger;
use App\Models\WorkflowTrigger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('workflows:run-schedules')]
#[Description('Start every workflow whose schedule trigger is due')]
class RunScheduledWorkflows extends Command
{
    public function handle(Engine $engine): int
    {
        $started = 0;

        $due = WorkflowTrigger::query()
            ->where('type', WorkflowTrigger::SCHEDULE)
            ->where('next_run_at', '<=', now())
            ->with('workflow.publishedVersion')
            ->limit(500)
            ->get();

        foreach ($due as $trigger) {
            $scheduledAt = $trigger->next_run_at;
            $next = ScheduleTrigger::nextRunAfter($trigger->config(), now());

            $claimed = WorkflowTrigger::query()
                ->whereKey($trigger->id)
                ->where('next_run_at', $scheduledAt)
                ->update(['next_run_at' => $next]);

            if ($claimed === 0 || ! $trigger->workflow->publishedVersion) {
                continue;
            }

            try {
                $engine->start($trigger->workflow, ['scheduled_at' => $scheduledAt->toIso8601String()], triggerType: 'schedule', triggerNodeId: $trigger->node_id);
                $started++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->components->info("Started {$started} scheduled run(s).");

        return self::SUCCESS;
    }
}
