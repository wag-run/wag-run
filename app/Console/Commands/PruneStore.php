<?php

namespace App\Console\Commands;

use App\Services\DataStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:prune-store')]
#[Description('Remove data store values that have expired or belong to a deleted workflow')]
class PruneStore extends Command
{
    public function handle(DataStore $store): int
    {
        $this->components->info(sprintf('Removed %d data store values.', $store->prune()));

        return self::SUCCESS;
    }
}
