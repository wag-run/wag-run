<?php

namespace App\Enums;

/**
 * A user's role on the install. Stored on the organization membership so it
 * becomes the organization admin role when the SaaS version arrives.
 */
enum InstallRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Organization admin'),
            self::Member => __('Member'),
        };
    }
}
