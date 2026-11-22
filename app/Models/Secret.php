<?php

namespace App\Models;

use Database\Factories\SecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sensitive workspace value. Encrypted at rest with the app key, never
 * serialized, and revealed in the UI only to admins (each reveal is audited).
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $key
 * @property string $value
 * @property string|null $description
 */
#[Fillable(['key', 'value', 'description'])]
#[Hidden(['value'])]
class Secret extends Model
{
    /** @use HasFactory<SecretFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
