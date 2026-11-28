<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Fortify's step that asks for a two-factor code during password sign-in,
 * skipped when every organization the person is active in has two-factor
 * authentication disabled. Their two-factor setup is kept, so they're asked
 * again if an organization turns it back on.
 */
class AskForTwoFactorCode extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && $user->hasTwoFactorSetUp() && ! $user->twoFactorIsOffEverywhere()) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }
}
