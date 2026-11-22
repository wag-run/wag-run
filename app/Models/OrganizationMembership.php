<?php

namespace App\Models;

use App\Enums\OrgRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property OrgRole $role
 * @property Carbon|null $deactivated_at
 */
class OrganizationMembership extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => OrgRole::class,
            'deactivated_at' => 'datetime',
        ];
    }
}
