<?php

namespace App\Models;

use Database\Factories\OidcDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An email domain that belongs to one sign-in provider. A provider with
 * domains accepts only email addresses on them; one with none accepts any.
 * Each domain belongs to one provider in its organization, so a domain never
 * points at two providers.
 *
 * @property string $id a UUID
 * @property string $organization_id
 * @property string $oidc_provider_id
 * @property string $domain lowercase, such as acme.com
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OidcProvider $provider
 */
#[Fillable(['organization_id', 'oidc_provider_id', 'domain'])]
class OidcDomain extends Model
{
    /** @use HasFactory<OidcDomainFactory> */
    use HasFactory, HasUuids;

    /**
     * A domain as people type it ("@Acme.com", "https://acme.com/"), as stored.
     */
    public static function normalize(string $domain): string
    {
        $domain = Str::lower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);

        return trim(ltrim($domain, '@'), '/.');
    }

    /**
     * The domain of an email address, lowercase, or null when it has none.
     */
    public static function ofEmail(?string $email): ?string
    {
        $domain = $email !== null ? Str::lower(trim(Str::afterLast($email, '@'))) : '';

        return $email !== null && str_contains($email, '@') && $domain !== '' ? $domain : null;
    }

    /**
     * @return BelongsTo<OidcProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(OidcProvider::class, 'oidc_provider_id');
    }
}
