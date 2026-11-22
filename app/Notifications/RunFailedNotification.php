<?php

namespace App\Notifications;

use App\Models\Execution;
use Illuminate\Notifications\Notification;

/**
 * Tells a workflow's owner, in the app, that one of its runs failed.
 * An email channel comes later.
 */
class RunFailedNotification extends Notification
{
    public function __construct(public Execution $execution) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string, workspace_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $workflow = $this->execution->workflow;

        return [
            'title' => __('notifications.run_failed.run_id_of_workflow_failed', ['id' => $this->execution->id, 'workflow' => $workflow->name]),
            'body' => (string) ($this->execution->error['message'] ?? __('common.run_failed')),
            'url' => route('workspaces.executions.show', [$workflow->workspace, $this->execution]),
            'icon' => 'exclamation-triangle',
            'workspace_id' => $workflow->workspace_id,
        ];
    }
}
