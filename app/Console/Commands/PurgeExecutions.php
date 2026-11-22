<?php

namespace App\Console\Commands;

use App\Services\ExecutionRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:purge-executions')]
#[Description('Remove execution logs that have passed their retention period')]
class PurgeExecutions extends Command
{
    public function handle(ExecutionRetention $retention): int
    {
        $this->components->info(sprintf('Removed %d executions past their retention period.', $retention->purge()));

        return self::SUCCESS;
    }
}
