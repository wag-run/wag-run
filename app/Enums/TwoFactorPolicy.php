<?php

namespace App\Enums;

/**
 * Whether an organization asks for two-factor authentication on password
 * sign-ins. It doesn't apply to sign-ins through a provider, which runs its
 * own, or while the organization has password sign-in off.
 */
enum TwoFactorPolicy: string
{
    /** Everyone signing in with a password must set it up before going on. */
    case Required = 'required';

    /** People choose; those who set it up are asked for a code. */
    case Optional = 'optional';

    /** Nobody is asked for a code and setup is hidden. Saved setups are kept. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Required => __('enums.two_factor_policy.required'),
            self::Optional => __('enums.two_factor_policy.optional'),
            self::Disabled => __('enums.two_factor_policy.disabled'),
        };
    }
}
