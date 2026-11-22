<?php

namespace App\Enums;

enum ConcurrencyPolicy: string
{
    /** Multiple concurrent runs. */
    case Allow = 'allow';

    /** One run at a time: a new run is dropped and recorded as skipped. */
    case SkipIfRunning = 'skip';

    /** One run at a time: a new run cancels the previous one. */
    case CancelPrevious = 'cancel_previous';

    public function label(): string
    {
        return match ($this) {
            self::Allow => __('enums.concurrency_policy.allow_concurrent_runs'),
            self::SkipIfRunning => __('enums.concurrency_policy.one_at_a_time_skip'),
            self::CancelPrevious => __('enums.concurrency_policy.one_at_a_time_new'),
        };
    }
}
