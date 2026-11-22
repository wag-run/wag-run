<?php

namespace App\Notifications;

use App\Enums\AssignmentType;
use App\Models\Assignment;
use Illuminate\Notifications\Notification;

/**
 * Tells an assignee, in the app, that an approval or task is waiting on
 * them. An email channel comes later.
 */
class AssignmentNotification extends Notification
{
    public function __construct(public Assignment $assignment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string, workspace_id: int, assignment_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $approval = $this->assignment->type === AssignmentType::Approval;

        return [
            'title' => $approval
                ? __('Approval requested: :title', ['title' => $this->assignment->title])
                : __('Task assigned: :title', ['title' => $this->assignment->title]),
            'body' => __(':workflow in :workspace', ['workflow' => $this->assignment->execution->workflow->name, 'workspace' => $this->assignment->workspace->name]),
            'url' => route('workspaces.inbox.show', [$this->assignment->workspace, $this->assignment]),
            'icon' => $approval ? 'check-badge' : 'clipboard-document-check',
            'workspace_id' => $this->assignment->workspace_id,
            'assignment_id' => $this->assignment->id,
        ];
    }
}
