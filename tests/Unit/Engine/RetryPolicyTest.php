<?php

namespace Tests\Unit\Engine;

use App\Engine\RetryPolicy;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    public function test_allows_one_attempt_per_retry_after_the_first(): void
    {
        $policy = new RetryPolicy(maxRetries: 2);

        $this->assertTrue($policy->allowsAnotherAttempt(1));
        $this->assertTrue($policy->allowsAnotherAttempt(2));
        $this->assertFalse($policy->allowsAnotherAttempt(3));
    }

    public function test_backoff_grows_by_the_multiplier(): void
    {
        $policy = new RetryPolicy(maxRetries: 3, backoffSeconds: 30, multiplier: 2);

        $this->assertSame([30, 60, 120], [$policy->delayAfter(1), $policy->delayAfter(2), $policy->delayAfter(3)]);
    }

    public function test_step_override_replaces_only_the_values_it_sets_and_is_clamped(): void
    {
        $policy = (new RetryPolicy(maxRetries: 1, backoffSeconds: 30, multiplier: 2))
            ->merge(['max_retries' => 99, 'multiplier' => 0.1]);

        $this->assertSame(25, $policy->maxRetries);
        $this->assertSame(30, $policy->backoffSeconds);
        $this->assertSame(1.0, $policy->multiplier);
    }
}
