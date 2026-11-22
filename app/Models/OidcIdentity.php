<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Links a user to the subject an OIDC provider knows them by.
 *
 * @property int $id
 * @property int $oidc_provider_id
 * @property int $user_id
 * @property string $subject
 * @property string|null $email
 * @property Carbon|null $last_signed_in_at
 * @property-read User $user
 * @property-read OidcProvider $provider
 */
#[Fillable(['oidc_provider_id', 'user_id', 'subject', 'email', 'last_signed_in_at'])]
class OidcIdentity extends Model
{
    protected function casts(): array
    {
        return [
            'last_signed_in_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<OidcProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(OidcProvider::class, 'oidc_provider_id');
    }
}
