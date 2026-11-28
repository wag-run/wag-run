<?php

namespace App\Models;

use App\Concerns\HasSettings;
use App\Engine\Redactor;
use App\Enums\OrgRole;
use App\Support\CurrentOrganization;
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
 * @property array<string, mixed>|null $settings the organization-wide settings, see {@see Organization::SETTINGS}
 * @property array{host?: string, port?: int, encryption?: string, username?: string|null, password?: string|null, from_address?: string, from_name?: string|null}|null $mail
 * @property int|null $default_retention_days
 * @property int $max_chain_depth
 * @property int $max_runs_per_minute
 * @property bool $allow_account_deletion
 * @property bool $password_sign_in
 * @property list<string> $sensitive_keys extra header and field names whose values runs mask, see {@see Redactor}
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'settings', 'mail', 'default_retention_days', 'max_chain_depth', 'max_runs_per_minute', 'allow_account_deletion', 'sensitive_keys', 'password_sign_in'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasSettings, HasUuids;

    /**
     * The organization-wide settings, with their defaults. They live in the
     * `settings` JSON column, see {@see HasSettings}.
     */
    public const SETTINGS = [
        'default_retention_days' => ['int', 30],
        'max_chain_depth' => ['int', 10],
        'max_runs_per_minute' => ['int', 60],
        'allow_account_deletion' => ['bool', true],
        'password_sign_in' => ['bool', true],
        'sensitive_keys' => ['array', []],
    ];

    protected function casts(): array
    {
        return [
            'mail' => 'encrypted:array',
        ];
    }

    /**
     * The organization the current request or job is about, as
     * {@see CurrentOrganization} holds it, or null when there isn't one, such as
     * on the sign-in page. Code that handles a record (a run, a webhook, an
     * invitation) uses that record's organization instead.
     */
    public static function current(): ?self
    {
        return app(CurrentOrganization::class)->get();
    }

    /**
     * Whether people in the current organization may delete their own accounts.
     */
    public static function allowsAccountDeletion(): bool
    {
        return static::current()->allow_account_deletion ?? true;
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
     * @return HasMany<OidcDomain, $this>
     */
    public function oidcDomains(): HasMany
    {
        return $this->hasMany(OidcDomain::class);
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
