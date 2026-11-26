<?php

namespace App\Console\Commands;

use App\Models\WorkflowTrigger;
use App\Services\TriggerSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:sync-webhooks')]
#[Description('Register webhooks with other apps again where their URL has changed, as when APP_URL changes')]
class SyncWebhooks extends Command
{
    public function handle(TriggerSync $sync): int
    {
        $moved = 0;
        $failed = 0;

        WorkflowTrigger::query()
            ->where('type', WorkflowTrigger::SUBSCRIPTION)
            ->with('workflow.workspace')
            ->lazyById()
            ->each(function (WorkflowTrigger $trigger) use ($sync, &$moved, &$failed) {
                $result = $sync->moveWebhook($trigger);

                if ($result === true) {
                    $moved++;
                } elseif ($result === false) {
                    $failed++;
                    $this->components->warn("Could not move the webhook for {$trigger->workflow->name} ({$trigger->node_id}): {$trigger->state['error']}");
                }
            });

        $this->components->info("Moved {$moved} webhook(s) to ".config('app.url').'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
