<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Mail;

/**
 * Turns SMTP settings saved in the app, the organization's or a workspace
 * authentication setting's, into Laravel mailers. Without organization SMTP
 * settings, mail goes through the server's own mail configuration.
 */
class Mailers
{
    public const ORGANIZATION = 'organization';

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /**
     * The mailer the app's own emails, and Send email nodes without their own
     * SMTP server, go through.
     */
    public function forOrganization(): string
    {
        $smtp = Organization::current()?->mail;

        return $smtp ? $this->register(self::ORGANIZATION, $smtp) : (string) config('mail.default');
    }

    /**
     * Register (or refresh) an SMTP mailer named $name.
     *
     * @param  array<string, mixed>  $smtp  host, port, encryption, username, password, from_address, from_name
     */
    public function register(string $name, array $smtp): string
    {
        config(["mail.mailers.{$name}" => [
            'transport' => 'smtp',
            'scheme' => ($smtp['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'host' => (string) ($smtp['host'] ?? ''),
            'port' => (int) ($smtp['port'] ?? 587),
            'username' => filled($smtp['username'] ?? null) ? (string) $smtp['username'] : null,
            'password' => filled($smtp['password'] ?? null) ? (string) $smtp['password'] : null,
            'auto_tls' => ($smtp['encryption'] ?? 'tls') !== 'none',
            'require_tls' => ($smtp['encryption'] ?? 'tls') === 'tls',
            'timeout' => 30,
            'from' => [
                'address' => (string) ($smtp['from_address'] ?? config('mail.from.address')),
                'name' => filled($smtp['from_name'] ?? null) ? (string) $smtp['from_name'] : config('mail.from.name'),
            ],
        ]]);

        Mail::purge($name);

        return $name;
    }
}
