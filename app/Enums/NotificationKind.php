<?php

namespace App\Enums;

/**
 * The notifications a person can choose channels for. Invitations are not
 * here: they go to people who have no account yet, so there is nothing to
 * choose.
 */
enum NotificationKind: string
{
    case RunFailed = 'run_failed';
    case Assignment = 'assignment';
    case Workflow = 'workflow';

    public function label(): string
    {
        return __("settings.notifications.kinds.{$this->value}.label");
    }

    public function description(): string
    {
        return __("settings.notifications.kinds.{$this->value}.description");
    }
}
