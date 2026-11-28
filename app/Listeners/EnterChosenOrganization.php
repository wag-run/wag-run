<?php

namespace App\Listeners;

use App\Http\Controllers\SignInEmailController;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Request;

/**
 * Someone in several organizations picks one on the sign-in page, see
 * {@see SignInEmailController::chooseOrganization()}. Signing in takes them
 * into it, so its sign-in rules are the ones checked.
 */
class EnterChosenOrganization
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! Request::hasSession()) {
            return;
        }

        $organizationId = Request::session()->pull(SignInEmailController::ORGANIZATION_KEY);

        if (is_string($organizationId) && $event->user->current_organization_id !== $organizationId && $event->user->isActiveIn($organizationId)) {
            $event->user->forceFill(['current_organization_id' => $organizationId])->save();
        }
    }
}
