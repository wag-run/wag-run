<?php

namespace App\Models;

use App\Enums\OrgRole;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $organization_id
 * @property int|null $workspace_id
 * @property string|null $invited_by
 * @property string $email
 * @property OrgRole $org_role
 * @property WorkspaceRole|null $workspace_role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property-read Workspace|null $workspace
 * @property-read Organization $organization
 * @property-read User|null $inviter
 */
#[Fillable(['organization_id', 'workspace_id', 'invited_by', 'email', 'org_role', 'workspace_role', 'token_hash', 'expires_at'])]
class Invitation extends Model
{
    protected function casts(): array
    {
        return [
            'org_role' => OrgRole::class,
            'workspace_role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', static::hashToken($token))->first();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<Invitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
