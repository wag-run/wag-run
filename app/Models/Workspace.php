<?php

namespace App\Models;

use App\Concerns\HasSettings;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings the workspace's settings, see {@see Workspace::SETTINGS}
 * @property int|null $retention_days
 * @property list<string> $internal_allowlist
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
#[Fillable(['name', 'slug', 'settings', 'retention_days', 'internal_allowlist'])]
#[RouteKey('slug')]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasSettings;

    /**
     * The workspace's settings, see {@see HasSettings}. A null retention
     * means the organization default applies.
     */
    public const SETTINGS = [
        'retention_days' => ['int', null],
        'internal_allowlist' => ['array', []],
    ];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            if (blank($workspace->slug)) {
                $workspace->slug = static::slugFor($workspace->name);
            }
        });
    }

    /**
     * A slug no other workspace uses: the name, then a short random string,
     * such as "sales-k3x9q2". The random part keeps common names from
     * colliding, and slugs are unique across organizations, because a
     * workspace's URL has only its slug in it.
     */
    public static function slugFor(string $name): string
    {
        $base = rtrim(Str::limit(Str::slug($name), 40, ''), '-') ?: 'workspace';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (static::query()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * Retention in days for this workspace's execution logs: its own setting,
     * else the organization default. Null means logs are kept forever.
     */
    public function effectiveRetentionDays(): ?int
    {
        return $this->retention_days ?? $this->organization->default_retention_days;
    }

    /**
     * Up to two letters that stand for the workspace, the first and last
     * words' initials, shown in place of its name in the collapsed sidebar.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'>
     */
    public function membersWithRole(WorkspaceRole $role): BelongsToMany
    {
        return $this->members()->wherePivot('role', $role->value);
    }

    /**
     * @return HasMany<StoreEntry, $this>
     */
    public function storeEntries(): HasMany
    {
        return $this->hasMany(StoreEntry::class);
    }

    /**
     * @return HasMany<Secret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(Secret::class);
    }

    /**
     * @return HasMany<Credential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    /**
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
