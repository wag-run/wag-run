<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Services\Invitations;
use App\Services\Mailers;
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
        $inviter = $this->invitation->inviter->name ?? __('common.admin');
        $target = $this->invitation->workspace->name ?? config('app.name');

        return (new MailMessage)
            ->mailer(app(Mailers::class)->forOrganization())
            ->subject(__('notifications.invitation.you_have_been_invited_to', ['target' => $target]))
            ->line(__('notifications.invitation.inviter_invited_you_to_join', ['inviter' => $inviter, 'target' => $target]))
            ->action(__('common.accept_invitation'), route('invitations.show', $this->token))
            ->line(__('notifications.invitation.this_invitation_expires_in_days', ['days' => Invitations::EXPIRES_IN_DAYS]));
    }
}
