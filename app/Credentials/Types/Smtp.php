<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

/**
 * A workspace's own SMTP server, for Send email nodes that should not send
 * through the organization's mail settings.
 */
class Smtp extends CredentialType
{
    public function key(): string
    {
        return 'smtp';
    }

    public function label(): string
    {
        return __('credentials.smtp.smtp_server');
    }

    public function description(): string
    {
        return __('credentials.smtp.an_smtp_server_send_email');
    }

    public function fields(): array
    {
        return [
            CredentialField::text('host', __('credentials.smtp.host')),
            CredentialField::text('port', __('credentials.smtp.port'), default: '587'),
            CredentialField::select('encryption', __('credentials.smtp.encryption'), self::encryptionOptions(), default: 'tls'),
            CredentialField::text('username', __('credentials.smtp.username'), required: false),
            CredentialField::secret('password', __('common.password'), required: false),
            CredentialField::text('from_address', __('credentials.smtp.from_address')),
            CredentialField::text('from_name', __('credentials.smtp.from_name'), required: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function encryptionOptions(): array
    {
        return [
            'tls' => __('credentials.smtp.starttls'),
            'ssl' => __('credentials.smtp.ssl_tls'),
            'none' => __('credentials.smtp.none'),
        ];
    }
}
