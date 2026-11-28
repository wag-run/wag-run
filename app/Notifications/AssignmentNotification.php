<?php

namespace App\Notifications;

use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Services\Mailers;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an assignee that an approval or task is waiting on them: in the
 * app, by email, or both, as the assignee has chosen.
 */
class AssignmentNotification extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public Assignment $assignment,
        public array $channels = ['database'],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $assignment = $this->assignment;
        $approval = $assignment->type === AssignmentType::Approval;

        $message = (new MailMessage)
            ->mailer(app(Mailers::class)->forOrganization($this->assignment->workspace->organization))
            ->subject($this->title())
            ->line(__('notifications.assignment.workflow_in_workspace_is_waiting', [
                'workflow' => $assignment->execution->workflow->name,
                'workspace' => $assignment->workspace->name,
            ]));

        foreach (preg_split('/\R{2,}/', (string) $assignment->message) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $message->line($paragraph);
            }
        }

        if ($assignment->due_at) {
            $message->line(__('notifications.assignment.respond_by', ['time' => $assignment->due_at->toDayDateTimeString()]));
        }

        return $message->action(
            $approval ? __('notifications.assignment.review_and_respond') : __('notifications.assignment.open_task'),
            $this->url(),
        );
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string, workspace_id: int, assignment_id: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => __('notifications.assignment.workflow_in_workspace', ['workflow' => $this->assignment->execution->workflow->name, 'workspace' => $this->assignment->workspace->name]),
            'url' => $this->url(),
            'icon' => $this->assignment->type === AssignmentType::Approval ? 'check-badge' : 'clipboard-document-check',
            'workspace_id' => $this->assignment->workspace_id,
            'assignment_id' => $this->assignment->id,
        ];
    }

    protected function title(): string
    {
        return $this->assignment->type === AssignmentType::Approval
            ? __('notifications.assignment.approval_requested_title', ['title' => $this->assignment->title])
            : __('notifications.assignment.task_assigned_title', ['title' => $this->assignment->title]);
    }

    protected function url(): string
    {
        return route('workspaces.inbox.show', [$this->assignment->workspace, $this->assignment]);
    }
}
