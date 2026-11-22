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
            self::Allow => __('Allow concurrent runs'),
            self::SkipIfRunning => __('One at a time, skip new runs'),
            self::CancelPrevious => __('One at a time, new run cancels the previous'),
        };
    }
}
