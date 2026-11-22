<?php

namespace App\Engine\Events;

use App\Models\Execution;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An execution reached a final status: complete, failed, cancelled or skipped.
 */
class ExecutionFinished
{
    use Dispatchable;

    public function __construct(public Execution $execution) {}
}
