<?php

namespace App\Credentials;

use Illuminate\Http\Client\PendingRequest;

/**
 * An authentication type the HTTP call node can apply to a request.
 */
interface HttpAuthenticator
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function authenticate(PendingRequest $request, array $config): PendingRequest;
}
