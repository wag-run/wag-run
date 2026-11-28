<?php

namespace App\Models;

use App\Enums\OrgRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $organization_id
 * @property string $user_id
 * @property OrgRole $role
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $first_workspace_at when the person's first workspace was made, or found already there
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
            'first_workspace_at' => 'datetime',
        ];
    }
}
