<?php

namespace App\Engine;

use App\Enums\ResumeReason;

/**
 * Why a waiting step is being resumed, and what it was resumed with.
 */
final readonly class Resumption
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ResumeReason $reason,
        public array $payload = [],
    ) {}
}
