<?php

namespace App\Enums;

/**
 * A user's role in the organization. Stored on the organization membership so it
 * becomes the organization admin role when the SaaS version arrives.
 */
enum OrgRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('enums.org_role.organization_admin'),
            self::Member => __('enums.org_role.member'),
        };
    }
}
