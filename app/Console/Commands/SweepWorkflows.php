<?php

namespace App\Console\Commands;

use App\Engine\Sweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:sweep')]
#[Description('Queue ready steps nobody picked up, fail steps whose worker died, and resume elapsed waits')]
class SweepWorkflows extends Command
{
    public function handle(Sweeper $sweeper): int
    {
        $report = $sweeper->sweep();

        $this->components->info(sprintf(
            'Requeued %d, worker lost %d, resumed %d, resettled %d.',
            $report['requeued'], $report['worker_lost'], $report['resumed'], $report['resettled'],
        ));

        return self::SUCCESS;
    }
}
