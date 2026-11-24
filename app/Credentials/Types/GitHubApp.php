<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;
use App\Engine\Nodes\GitHub\GitHub;

/**
 * A GitHub App installed on an account or organization. The GitHub nodes
 * act as the app, with the permissions and repositories its installation
 * was given, using short-lived installation tokens signed for with the
 * app's private key ({@see GitHub::installationToken()}).
 */
class GitHubApp extends CredentialType
{
    public function key(): string
    {
        return 'github_app';
    }

    public function label(): string
    {
        return __('credentials.github_app.label');
    }

    public function description(): string
    {
        return __('credentials.github_app.description');
    }

    public function fields(): array
    {
        return [
            CredentialField::text('app_id', __('credentials.github_app.app_id'), help: __('credentials.github_app.app_id_help')),
            CredentialField::text('installation_id', __('credentials.github_app.installation_id'), help: __('credentials.github_app.installation_id_help')),
            CredentialField::secret('private_key', __('credentials.github_app.private_key'), help: __('credentials.github_app.private_key_help'), multiline: true),
        ];
    }

    /**
     * The installation ID is a number.
     */
    public function rules(string $prefix = 'config'): array
    {
        $rules = parent::rules($prefix);
        $rules["{$prefix}.installation_id"][] = 'regex:/^\d+$/';

        return $rules;
    }
}
