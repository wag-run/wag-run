<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

class ApiToken extends CredentialType
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
}
