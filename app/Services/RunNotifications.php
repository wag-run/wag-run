<?php

namespace App\Services;

use App\Enums\ExecutionStatus;
use App\Enums\WorkspacePermission;
use App\Models\Execution;
use App\Notifications\RunFailedNotification;

/**
 * In-app notifications about runs. Email comes later.
 */
class RunNotifications
{
    /**
     * Tell the workflow's owner that a run failed, as long as they can still
     * sign in and see the workspace's runs.
     */
    public function notifyOwnerIfFailed(Execution $execution): void
    {
        if ($execution->status !== ExecutionStatus::Failed) {
            return;
        }

        $owner = $execution->workflow->owner;

        if (! $owner || $owner->isDeactivated() || ! $owner->hasWorkspacePermission($execution->workspace, WorkspacePermission::ViewExecutions)) {
            return;
        }

        $owner->notify(new RunFailedNotification($execution));
    }
}
