<?php

namespace App\Models;

use App\Enums\ValueType;
use Database\Factories\StoreEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An entry in a workspace's data store, which keeps typed values between
 * runs. It holds a single value, or, as a collection, many values of its
 * type under item keys taken from data. Workflows can read a read-only
 * entry but not change it. Its key, type, and whether it is a collection
 * are fixed once created.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $key
 * @property ValueType $type
 * @property bool $is_collection
 * @property bool $read_only
 * @property string|null $description
 * @property Carbon $updated_at
 */
#[Fillable(['workspace_id', 'key', 'type', 'is_collection', 'read_only', 'description'])]
class StoreEntry extends Model
{
    /** @use HasFactory<StoreEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ValueType::class,
            'is_collection' => 'boolean',
            'read_only' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The entry's one value under the item key '', or a collection's items.
     *
     * @return HasMany<StoreValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(StoreValue::class);
    }
}
