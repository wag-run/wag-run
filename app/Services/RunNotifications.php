<?php

namespace App\Services;

use App\Enums\ExecutionStatus;
use App\Enums\NotificationKind;
use App\Enums\WorkspacePermission;
use App\Models\Execution;
use App\Notifications\RunFailedNotification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Notifications about runs, in the app and by email as each person prefers.
 */
class RunNotifications
{
    /**
     * Tell the workflow's owner that a run failed, as long as they can still
     * sign in and see the workspace's runs.
     */
    public function notifyOwnerIfFailed(Execution $execution): void
    {
        if ($execution->status !== ExecutionStatus::Failed || $execution->is_test) {
            return;
        }

        $owner = $execution->workflow->owner;

        if (! $owner || $owner->isDeactivated() || ! $owner->hasWorkspacePermission($execution->workspace, WorkspacePermission::ViewExecutions)) {
            return;
        }

        if ($owner->wantsNotification(NotificationKind::RunFailed, 'database')) {
            $owner->notify(new RunFailedNotification($execution));
        }

        if (! $owner->wantsNotification(NotificationKind::RunFailed, 'mail')) {
            return;
        }

        try {
            $owner->notify(new RunFailedNotification($execution, ['mail']));
        } catch (TransportExceptionInterface $exception) {
            report($exception);
        }
    }
}
