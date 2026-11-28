<?php

namespace App\Notifications;

use App\Models\Workspace;
use App\Services\Mailers;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notification a workflow's Send notification node sends, in the app, by
 * email, or both, as each recipient has chosen.
 */
class WorkflowNotification extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $title,
        public ?string $body,
        public ?string $url,
        public int $workspaceId,
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
        $message = (new MailMessage)
            ->mailer(app(Mailers::class)->forOrganization(Workspace::query()->find($this->workspaceId)?->organization))
            ->subject($this->title);

        foreach (preg_split('/\R{2,}/', (string) $this->body) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $message->line($paragraph);
            }
        }

        if ($this->url) {
            $message->action(__('notifications.workflow.open'), $this->url);
        }

        return $message;
    }

    /**
     * @return array{title: string, body: string, url: string|null, icon: string, workspace_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => (string) $this->body,
            'url' => $this->url,
            'icon' => 'bell',
            'workspace_id' => $this->workspaceId,
        ];
    }
}
