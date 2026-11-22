<?php

namespace App\Jobs;

use App\Engine\StepRunner;
use App\Enums\ResumeReason;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Continues a waiting step: its timer elapsed, a response arrived, or the
 * child workflow it waits on finished.
 */
class ResumeStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $stepId,
        public ResumeReason $reason,
        public array $payload = [],
    ) {}

    public function handle(StepRunner $runner): void
    {
        $runner->resume($this->stepId, $this->reason, $this->payload);
    }
}
