<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Services\Invitations;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(public Invitation $invitation, public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->invitation->inviter->name ?? __('An admin');
        $target = $this->invitation->workspace->name ?? config('app.name');

        return (new MailMessage)
            ->subject(__('You have been invited to :target', ['target' => $target]))
            ->line(__(':inviter invited you to join :target.', ['inviter' => $inviter, 'target' => $target]))
            ->action(__('Accept invitation'), route('invitations.show', $this->token))
            ->line(__('This invitation expires in :days days.', ['days' => Invitations::EXPIRES_IN_DAYS]));
    }
}
