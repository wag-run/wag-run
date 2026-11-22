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
        return __('API token');
    }

    public function description(): string
    {
        return __('A token sent in a request header, such as a bearer token.');
    }

    public function fields(): array
    {
        return [
            CredentialField::text('header', __('Header name'), default: 'Authorization'),
            CredentialField::text('prefix', __('Value prefix'), required: false, default: 'Bearer', help: __('Sent before the token, separated by a space. Leave empty to send the token alone.')),
            CredentialField::secret('token', __('Token')),
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
