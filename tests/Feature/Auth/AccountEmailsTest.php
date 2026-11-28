<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\Mailers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The account emails Laravel sends on the app's behalf go through the
 * organization's email settings like its other emails.
 */
class AccountEmailsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{\Closure(): BaseNotification}>
     */
    public static function accountEmails(): array
    {
        return [
            'email confirmation' => [fn () => new VerifyEmailNotification],
            'password reset' => [fn () => new ResetPasswordNotification('token')],
        ];
    }

    #[DataProvider('accountEmails')]
    public function test_account_emails_go_through_the_organizations_smtp_server_when_it_has_one(\Closure $notification): void
    {
        $user = User::factory()->orgMember()->unverified()->create();

        $this->assertSame(config('mail.default'), $notification()->toMail($user)->mailer);

        $this->organization()->update(['mail' => ['host' => 'smtp.acme.test', 'port' => 587, 'from_address' => 'robot@acme.test']]);

        $this->assertSame(Mailers::ORGANIZATION, $notification()->toMail($user->refresh())->mailer);
    }

    public function test_the_confirmation_email_is_the_apps_own(): void
    {
        Notification::fake();
        $user = User::factory()->orgMember()->unverified()->create();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }
}
