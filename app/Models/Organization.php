<?php

namespace App\Models;

use App\Enums\OrgRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The organization layer above workspaces. The first release serves one
 * organization per install; see {@see Organization::current()}.
 *
 * @property string $id a UUID
 * @property string $name
 * @property int|null $default_retention_days
 * @property bool $registration_open
 * @property array<string, mixed>|null $settings
 * @property array{host?: string, port?: int, encryption?: string, username?: string|null, password?: string|null, from_address?: string, from_name?: string|null}|null $mail
 * @property int $max_chain_depth
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'default_retention_days', 'registration_open', 'settings', 'mail', 'max_chain_depth'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'default_retention_days' => 'integer',
            'registration_open' => 'boolean',
            'settings' => 'array',
            'mail' => 'encrypted:array',
            'max_chain_depth' => 'integer',
        ];
    }

    /**
     * The organization this install serves.
     */
    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationMembership, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationMembership::class)
            ->as('membership')
            ->withPivot(['role', 'deactivated_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationMembership, 'membership'>
     */
    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', OrgRole::Admin->value);
    }

    /**
     * Organization admins who can still sign in.
     */
    public function activeAdminCount(): int
    {
        return $this->admins()->wherePivotNull('deactivated_at')->count();
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /**
     * @return HasMany<OidcProvider, $this>
     */
    public function oidcProviders(): HasMany
    {
        return $this->hasMany(OidcProvider::class);
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
