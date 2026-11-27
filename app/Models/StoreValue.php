<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One value in the data store: a single entry's value, under the item key
 * '', or one item of a collection.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $store_entry_id
 * @property string $item_key
 * @property string $value JSON
 * @property Carbon $updated_at
 */
#[Fillable(['workspace_id', 'store_entry_id', 'item_key', 'value'])]
class StoreValue extends Model
{
    /**
     * @return BelongsTo<StoreEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(StoreEntry::class, 'store_entry_id');
    }

    public function decoded(): mixed
    {
        return json_decode($this->value, true);
    }
}
