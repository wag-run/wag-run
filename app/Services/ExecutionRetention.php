<?php

namespace App\Services;

use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\Workflow;

/**
 * Removes execution logs once they pass their retention period: the
 * workflow's override, else its workspace's setting, else the install
 * default. Failed, cancelled and skipped runs are removed the same way as
 * completed ones; runs still in progress are never removed.
 */
class ExecutionRetention
{
    protected const CHUNK = 500;

    /**
     * @return int how many executions were removed
     */
    public function purge(): int
    {
        $removed = 0;

        Workflow::query()->with('workspace.organization')->chunkById(100, function ($workflows) use (&$removed) {
            foreach ($workflows as $workflow) {
                $days = $workflow->effectiveRetentionDays();

                if ($days !== null) {
                    $removed += $this->purgeWorkflow($workflow, $days);
                }
            }
        });

        return $removed;
    }

    protected function purgeWorkflow(Workflow $workflow, int $days): int
    {
        $removed = 0;
        $cutoff = now()->subDays($days);

        do {
            $ids = Execution::query()
                ->where('workflow_id', $workflow->id)
                ->whereNotIn('status', ExecutionStatus::inProgress())
                ->where('finished_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->pluck('id');

            $removed += Execution::query()->whereKey($ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $removed;
    }
}
