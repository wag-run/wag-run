<?php

namespace App\Models;

use Database\Factories\StoreEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One value in a workspace's data store, which workflows save and read
 * between runs. The namespace is empty for a value shared by every workflow
 * in the workspace, or the id of the one workflow it belongs to.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $namespace
 * @property string $key
 * @property string $value JSON
 * @property Carbon|null $expires_at
 * @property Carbon $updated_at
 */
#[Fillable(['workspace_id', 'namespace', 'key', 'value', 'expires_at'])]
class StoreEntry extends Model
{
    /** @use HasFactory<StoreEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * Entries that haven't expired.
     *
     * @param  Builder<StoreEntry>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
