<?php

use App\Credentials\Types\Smtp;
use App\Mail\WorkflowEmail;
use App\Models\Organization;
use App\Services\Mailers;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

new class extends Component {
    public bool $enabled = false;

    public string $host = '';

    public int|string $port = 587;

    public string $encryption = 'tls';

    public string $username = '';

    /**
     * Left empty to keep the saved password.
     */
    public string $password = '';

    public bool $hasPassword = false;

    public string $fromAddress = '';

    public string $fromName = '';

    public function mount(): void
    {
        $mail = Organization::current()->mail;

        $this->enabled = $mail !== null;
        $this->host = (string) ($mail['host'] ?? '');
        $this->port = $mail['port'] ?? 587;
        $this->encryption = (string) ($mail['encryption'] ?? 'tls');
        $this->username = (string) ($mail['username'] ?? '');
        $this->hasPassword = filled($mail['password'] ?? null);
        $this->fromAddress = (string) ($mail['from_address'] ?? config('mail.from.address'));
        $this->fromName = (string) ($mail['from_name'] ?? config('mail.from.name'));
    }

    public function save(): void
    {
        $this->authorize('org-admin');

        $organization = Organization::current();

        if (! $this->enabled) {
            if ($organization->mail !== null) {
                $organization->forceFill(['mail' => null])->save();
                Audit::record('organization.mail_updated', $organization, metadata: ['enabled' => false]);
            }

            $this->hasPassword = false;
            Flux::toast(variant: 'success', text: __('admin.email.email_settings_saved'));

            return;
        }

        $this->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(Mailers::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'fromAddress' => ['required', 'email', 'max:255'],
            'fromName' => ['nullable', 'string', 'max:255'],
        ]);

        $organization->forceFill(['mail' => [
            'host' => $this->host,
            'port' => (int) $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username ?: null,
            'password' => $this->password !== '' ? $this->password : ($this->username ? ($organization->mail['password'] ?? null) : null),
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName ?: null,
        ]])->save();

        $this->password = '';
        $this->hasPassword = filled($organization->mail['password'] ?? null);

        Audit::record('organization.mail_updated', $organization, metadata: ['enabled' => true, 'host' => $this->host]);
        Flux::toast(variant: 'success', text: __('admin.email.email_settings_saved'));
    }

    /**
     * Send a test email to the signed-in admin through the saved settings.
     */
    public function sendTest(Mailers $mailers): void
    {
        $this->authorize('org-admin');

        $user = Auth::user();

        try {
            Mail::mailer($mailers->forOrganization())->to($user->email)->send(new WorkflowEmail(
                __('admin.email.test_email_from_app', ['app' => config('app.name')]),
                __('admin.email.if_you_can_read_this'),
            ));
        } catch (TransportExceptionInterface $exception) {
            Flux::toast(variant: 'danger', text: __('admin.email.test_email_failed', ['message' => $exception->getMessage()]));

            return;
        }

        Flux::toast(variant: 'success', text: __('admin.email.test_email_sent_to', ['email' => $user->email]));
    }

    public function render()
    {
        return $this->view()->title(__('admin.email.title'));
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('admin.email.title')" :subheading="__('admin.email.the_smtp_server_the_app')" />

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <flux:switch wire:model.live="enabled" :label="__('admin.email.send_through_my_smtp_server')"
            :description="__('admin.email.when_off_email_uses_the')" data-test="mail-enabled" />

        @if ($enabled)
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="host" :label="__('credentials.smtp.host')" placeholder="smtp.example.com" class="sm:col-span-2" required />
                <flux:input wire:model="port" type="number" min="1" max="65535" :label="__('credentials.smtp.port')" required />
            </div>
            <flux:select wire:model="encryption" :label="__('credentials.smtp.encryption')">
                @foreach (Smtp::encryptionOptions() as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="username" :label="__('credentials.smtp.username')" autocomplete="off" />
                <flux:input wire:model="password" type="password" :label="__('common.password')" autocomplete="new-password"
                    :placeholder="$hasPassword ? __('admin.email.leave_empty_to_keep') : ''" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="fromAddress" type="email" :label="__('credentials.smtp.from_address')" required />
                <flux:input wire:model="fromName" :label="__('credentials.smtp.from_name')" />
            </div>
        @endif

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">{{ __('common.save_settings') }}</flux:button>
            <flux:button wire:click="sendTest" icon="paper-airplane" data-test="send-test-email">{{ __('admin.email.send_test_email') }}</flux:button>
        </div>
        <flux:text class="text-xs">{{ __('admin.email.test_uses_saved_settings') }}</flux:text>
    </form>
</section>
