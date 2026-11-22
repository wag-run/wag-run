<?php

namespace App\Engine;

/**
 * How often a failed step is retried, and how long to wait between attempts.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxRetries = 0,
        public int $backoffSeconds = 30,
        public float $multiplier = 2.0,
    ) {}

    /**
     * Read a node's per-step override from its graph config, falling back to
     * the node class default for anything not set.
     *
     * @param  array<string, mixed>|null  $override
     */
    public function merge(?array $override): self
    {
        if (! $override) {
            return $this;
        }

        return new self(
            maxRetries: max(0, min(25, (int) ($override['max_retries'] ?? $this->maxRetries))),
            backoffSeconds: max(0, (int) ($override['backoff_seconds'] ?? $this->backoffSeconds)),
            multiplier: max(1.0, (float) ($override['multiplier'] ?? $this->multiplier)),
        );
    }

    public function allowsAnotherAttempt(int $attempt): bool
    {
        return $attempt <= $this->maxRetries;
    }

    /**
     * Seconds to wait before attempt number $attempt + 1.
     */
    public function delayAfter(int $attempt): int
    {
        return (int) round($this->backoffSeconds * ($this->multiplier ** max(0, $attempt - 1)));
    }
}
