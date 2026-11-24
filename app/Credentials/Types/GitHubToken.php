<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

/**
 * A GitHub personal access token, classic or fine-grained. The GitHub nodes
 * act as the person who made it, with the access the token gives.
 */
class GitHubToken extends CredentialType
{
    public function key(): string
    {
        return 'github_token';
    }

    public function label(): string
    {
        return __('credentials.github_token.label');
    }

    public function description(): string
    {
        return __('credentials.github_token.description');
    }

    public function fields(): array
    {
        return [
            CredentialField::secret('token', __('credentials.github_token.token'), help: __('credentials.github_token.token_help')),
        ];
    }
}
