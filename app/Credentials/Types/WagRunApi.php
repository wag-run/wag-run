<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

/**
 * A personal access token for the REST API of this install, or of another
 * one when its address is given. The API nodes act as the person who made
 * the token, within the token's scopes.
 */
class WagRunApi extends CredentialType
{
    public function key(): string
    {
        return 'wag_run';
    }

    public function label(): string
    {
        return __('credentials.wag_run.label', ['app' => config('app.name')]);
    }

    public function description(): string
    {
        return __('credentials.wag_run.description', ['app' => config('app.name')]);
    }

    public function fields(): array
    {
        return [
            CredentialField::url('url', __('credentials.wag_run.url'), help: __('credentials.wag_run.url_help')),
            CredentialField::secret('token', __('credentials.wag_run.token')),
        ];
    }
}
