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
 * @property array<string, mixed>|null $settings the organization-wide settings, see {@see Organization::SETTINGS}
 * @property array{host?: string, port?: int, encryption?: string, username?: string|null, password?: string|null, from_address?: string, from_name?: string|null}|null $mail
 * @property int|null $default_retention_days
 * @property bool $registration_open
 * @property int $max_chain_depth
 * @property int $max_runs_per_minute
 * @property bool $allow_account_deletion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'settings', 'mail', 'default_retention_days', 'registration_open', 'max_chain_depth', 'max_runs_per_minute', 'allow_account_deletion'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    /**
     * The organization-wide settings, with their defaults. They live in the
     * `settings` JSON column and read and write like attributes
     * (`$organization->max_chain_depth`), so adding one needs no migration:
     * add it here and to the Fillable list above. The default's type is the
     * setting's type.
     */
    public const SETTINGS = [
        'default_retention_days' => 30,
        'registration_open' => false,
        'max_chain_depth' => 10,
        'max_runs_per_minute' => 60,
        'allow_account_deletion' => true,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'mail' => 'encrypted:array',
        ];
    }

    public function getAttribute($key)
    {
        if (! array_key_exists($key, self::SETTINGS)) {
            return parent::getAttribute($key);
        }

        $settings = $this->settings ?? [];
        $value = array_key_exists($key, $settings) ? $settings[$key] : self::SETTINGS[$key];

        return match (true) {
            $value === null => null,
            is_bool(self::SETTINGS[$key]) => (bool) $value,
            default => (int) $value,
        };
    }

    public function setAttribute($key, $value)
    {
        if (! array_key_exists($key, self::SETTINGS)) {
            return parent::setAttribute($key, $value);
        }

        return parent::setAttribute('settings', [...($this->settings ?? []), $key => $value]);
    }

    /**
     * The names of the attributes and settings that have unsaved changes.
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        $changed = array_keys($this->getDirty());

        if (in_array('settings', $changed, true)) {
            $before = $this->getOriginal('settings') ?? [];
            $after = $this->settings ?? [];
            $keys = array_map('strval', array_unique([...array_keys($before), ...array_keys($after)]));

            $changed = [
                ...array_diff($changed, ['settings']),
                ...array_filter($keys, fn (string $key) => ($before[$key] ?? null) !== ($after[$key] ?? null)),
            ];
        }

        return $changed;
    }

    /**
     * The organization this install serves.
     */
    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    /**
     * Whether people may delete their own accounts.
     */
    public static function allowsAccountDeletion(): bool
    {
        $settings = static::query()->oldest('id')->value('settings');

        return (bool) (is_array($settings) ? ($settings['allow_account_deletion'] ?? true) : true);
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
