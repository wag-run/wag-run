<?php

namespace App\Models;

use Database\Factories\OidcDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string $verification_token the value its DNS TXT record must hold
 * @property Carbon|null $verified_at when the organization proved it controls the domain
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OidcProvider $provider
 */
#[Fillable(['organization_id', 'oidc_provider_id', 'domain', 'verified_at'])]
class OidcDomain extends Model
{
    /** @use HasFactory<OidcDomainFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (OidcDomain $domain) {
            $domain->verification_token ??= Str::lower(Str::random(32));
        });
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Whether the organization has shown it controls the domain. A self-hosted
     * install serves one organization, so its domains count as verified
     * without a DNS record.
     */
    public function isVerified(): bool
    {
        return config('app.self_hosted') || $this->verified_at !== null;
    }

    /**
     * Only domains that count as verified, see {@see isVerified()}.
     *
     * @param  Builder<OidcDomain>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->when(! config('app.self_hosted'), fn (Builder $query) => $query->whereNotNull('verified_at'));
    }

    /**
     * The DNS name of the TXT record that verifies the domain.
     */
    public function txtRecordName(): string
    {
        return '_wag-run.'.$this->domain;
    }

    /**
     * The value that TXT record must hold.
     */
    public function txtRecordValue(): string
    {
        return 'wag-run-verify='.$this->verification_token;
    }

    /**
     * Whether another organization has already verified this domain. Only one
     * organization can.
     */
    public function isVerifiedElsewhere(): bool
    {
        return ! config('app.self_hosted') && static::query()
            ->where('domain', $this->domain)
            ->where('organization_id', '!=', $this->organization_id)
            ->whereNotNull('verified_at')
            ->exists();
    }

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
