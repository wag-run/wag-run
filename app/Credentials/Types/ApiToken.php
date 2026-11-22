<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;
use App\Credentials\HttpAuthenticator;
use Illuminate\Http\Client\PendingRequest;

class ApiToken extends CredentialType implements HttpAuthenticator
{
    public function key(): string
    {
        return 'api_token';
    }

    public function label(): string
    {
        return __('credentials.api_token.api_token');
    }

    public function description(): string
    {
        return __('credentials.api_token.token_sent_in_a_request');
    }

    public function fields(): array
    {
        return [
            CredentialField::text('header', __('credentials.api_token.header_name'), default: 'Authorization'),
            CredentialField::text('prefix', __('credentials.api_token.value_prefix'), required: false, default: 'Bearer', help: __('credentials.api_token.sent_before_the_token_separated')),
            CredentialField::secret('token', __('credentials.api_token.token')),
        ];
    }

    public function authenticate(PendingRequest $request, array $config): PendingRequest
    {
        $prefix = trim((string) ($config['prefix'] ?? ''));
        $token = (string) ($config['token'] ?? '');

        return $request->withHeaders([
            (string) ($config['header'] ?? 'Authorization') => $prefix === '' ? $token : "{$prefix} {$token}",
        ]);
    }
}
