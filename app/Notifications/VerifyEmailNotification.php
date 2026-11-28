<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use App\Services\Mailers;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The email that asks someone to confirm their address, sent through the
 * organization's email settings like the app's other emails.
 */
class VerifyEmailNotification extends VerifyEmail
{
    /**
     * The organization whose email settings the email goes through: the one
     * the person last worked in.
     */
    protected ?Organization $organization = null;

    /**
     * @param  User  $notifiable
     * @return MailMessage|Mailable
     */
    public function toMail($notifiable)
    {
        $this->organization = $notifiable->currentOrganization ?? $notifiable->organizations()->first();

        return parent::toMail($notifiable);
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        return parent::buildMailMessage($url)->mailer(app(Mailers::class)->forOrganization($this->organization));
    }
}
