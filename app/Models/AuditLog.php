<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $workspace_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id an integer or UUID key, stored as text
 * @property string|null $subject_label
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read Workspace|null $workspace
 */
#[Fillable(['organization_id', 'workspace_id', 'user_id', 'action', 'subject_type', 'subject_id', 'subject_label', 'metadata', 'ip_address', 'created_at'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Organization admins see every entry; workspace admins see only entries for
     * the workspaces they administer.
     *
     * @param  Builder<AuditLog>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isOrgAdmin()) {
            return;
        }

        $query->whereIn('workspace_id', $user->administeredWorkspaceIds());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
