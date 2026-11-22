<?php

namespace App\Models;

use Database\Factories\OidcProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An OpenID Connect provider people can sign in with, configured by organization
 * admins in the UI. The client secret is encrypted at rest.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string $issuer
 * @property string $client_id
 * @property string $client_secret
 * @property string $scopes
 * @property bool $enabled
 * @property bool $allow_registration
 * @property bool $trust_email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
#[Fillable(['name', 'slug', 'issuer', 'client_id', 'client_secret', 'scopes', 'enabled', 'allow_registration', 'trust_email'])]
#[Hidden(['client_secret'])]
#[RouteKey('slug')]
class OidcProvider extends Model
{
    /** @use HasFactory<OidcProviderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'enabled' => 'boolean',
            'allow_registration' => 'boolean',
            'trust_email' => 'boolean',
        ];
    }

    /**
     * The issuer without a trailing slash, as discovery documents give it.
     */
    public function normalizedIssuer(): string
    {
        return rtrim($this->issuer, '/');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<OidcIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(OidcIdentity::class);
    }
}
