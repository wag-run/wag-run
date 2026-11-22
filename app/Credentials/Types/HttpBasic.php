<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;
use App\Credentials\HttpAuthenticator;
use Illuminate\Http\Client\PendingRequest;

class HttpBasic extends CredentialType implements HttpAuthenticator
{
    public function key(): string
    {
        return 'http_basic';
    }

    public function label(): string
    {
        return __('credentials.http_basic.http_basic');
    }

    public function description(): string
    {
        return __('credentials.http_basic.username_and_password_sent_with');
    }

    public function fields(): array
    {
        return [
            CredentialField::text('username', __('credentials.http_basic.username')),
            CredentialField::secret('password', __('common.password')),
        ];
    }

    public function authenticate(PendingRequest $request, array $config): PendingRequest
    {
        return $request->withBasicAuth((string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''));
    }
}
