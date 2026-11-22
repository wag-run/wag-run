<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $user_id
 * @property WorkspaceRole $role
 */
class WorkspaceMembership extends Pivot
{
    protected $table = 'workspace_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }
}
