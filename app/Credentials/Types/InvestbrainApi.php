<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

/**
 * An Investbrain API token, for Investbrain Cloud or a self-hosted install
 * when its address is given. The Investbrain nodes act as the person who
 * made the token.
 */
class InvestbrainApi extends CredentialType
{
    public function key(): string
    {
        return 'investbrain';
    }

    public function label(): string
    {
        return __('credentials.investbrain.label');
    }

    public function description(): string
    {
        return __('credentials.investbrain.description');
    }

    public function fields(): array
    {
        return [
            CredentialField::url('url', __('credentials.investbrain.url'), help: __('credentials.investbrain.url_help')),
            CredentialField::secret('token', __('credentials.investbrain.token'), help: __('credentials.investbrain.token_help')),
        ];
    }
}
