<?php

namespace App\Notifications;

use App\Models\Execution;
use App\Services\Mailers;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a workflow's owner that one of its runs failed: in the app, and by
 * email when they have not turned that off.
 */
class RunFailedNotification extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public Execution $execution,
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
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->mailer(app(Mailers::class)->forOrganization($this->execution->workspace->organization))
            ->subject($data['title'])
            ->line($data['body'])
            ->action(__('notifications.run_failed.view_the_run'), $data['url']);
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string, workspace_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $workflow = $this->execution->workflow;

        return [
            'title' => __('notifications.run_failed.run_id_of_workflow_failed', ['id' => Execution::reference($this->execution->id), 'workflow' => $workflow->name]),
            'body' => (string) ($this->execution->error['message'] ?? __('common.run_failed')),
            'url' => route('workspaces.executions.show', [$workflow->workspace, $this->execution]),
            'icon' => 'exclamation-triangle',
            'workspace_id' => $workflow->workspace_id,
        ];
    }
}
