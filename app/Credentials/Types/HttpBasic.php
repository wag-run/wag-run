<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

class HttpBasic extends CredentialType
{
    public function key(): string
    {
        return 'http_basic';
    }

    public function label(): string
    {
        return __('HTTP Basic');
    }

    public function description(): string
    {
        return __('A username and password sent with HTTP Basic authentication.');
    }

    public function fields(): array
    {
        return [
            CredentialField::text('username', __('Username')),
            CredentialField::secret('password', __('Password')),
        ];
    }
}
