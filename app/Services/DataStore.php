<?php

namespace App\Services;

use App\Models\StoreEntry;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The workspace data store that workflows save to and read from between
 * runs: JSON values under keys, shared by the whole workspace or private to
 * one workflow (its namespace), optionally expiring.
 *
 * Increment and claim are single steps, safe when runs overlap: claim
 * relies on the unique key, and increment locks the row it changes.
 */
class DataStore
{
    public const KEY_MAX_LENGTH = 191;

    /**
     * @return string the namespace for a value shared by the workspace ('') or private to a workflow
     */
    public static function namespaceFor(bool $workflowOnly, string $workflowId): string
    {
        return $workflowOnly ? $workflowId : '';
    }

    /**
     * @throws RuntimeException when the key is empty or too long
     */
    public static function cleanKey(mixed $key): string
    {
        $key = is_scalar($key) ? trim((string) $key) : '';

        if ($key === '') {
            throw new RuntimeException(__('nodes.store.key_is_required'));
        }

        if (mb_strlen($key) > self::KEY_MAX_LENGTH) {
            throw new RuntimeException(__('nodes.store.key_too_long', ['max' => self::KEY_MAX_LENGTH]));
        }

        return $key;
    }

    /**
     * @return array{value: mixed, found: bool}
     */
    public function get(Workspace $workspace, string $namespace, string $key): array
    {
        $entry = $this->entries($workspace, $namespace, $key)->live()->first();

        return $entry ? ['value' => json_decode($entry->value, true), 'found' => true] : ['value' => null, 'found' => false];
    }

    /**
     * @throws RuntimeException when the value is too large or the workspace is full
     */
    public function put(Workspace $workspace, string $namespace, string $key, mixed $value, ?CarbonInterface $expiresAt = null): void
    {
        $json = $this->encode($value);

        if (! $this->entries($workspace, $namespace, $key)->live()->exists()) {
            $this->assertRoom($workspace, 1);
        }

        StoreEntry::query()->upsert(
            [['workspace_id' => $workspace->id, 'namespace' => $namespace, 'key' => $key, 'value' => $json, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]],
            ['workspace_id', 'namespace', 'key'],
            ['value', 'expires_at', 'updated_at'],
        );
    }

    public function forget(Workspace $workspace, string $namespace, string $key): bool
    {
        return $this->entries($workspace, $namespace, $key)->live()->delete() > 0;
    }

    /**
     * Add to a number, starting from 0 when the key is missing, and return
     * the new total. An expiry only applies when the key is created, so a
     * counter keeps the window it started with.
     *
     * @throws RuntimeException when the key holds something that isn't a number, or the workspace is full
     */
    public function increment(Workspace $workspace, string $namespace, string $key, int|float $by, ?CarbonInterface $expiresAt = null): int|float
    {
        return DB::transaction(function () use ($workspace, $namespace, $key, $by, $expiresAt) {
            $this->create($workspace, $namespace, $key, '0', $expiresAt);

            $entry = $this->entries($workspace, $namespace, $key)->lockForUpdate()->firstOrFail();
            $current = json_decode($entry->value);

            if (! is_int($current) && ! is_float($current)) {
                throw new RuntimeException(__('nodes.store.not_a_number', ['key' => $key]));
            }

            $total = $current + $by;
            $entry->forceFill(['value' => $this->encode($total)])->save();

            return $total;
        }, 3);
    }

    /**
     * Record a key and say whether it was new: true the first time, false
     * while it is already recorded.
     *
     * @throws RuntimeException when the workspace is full
     */
    public function claim(Workspace $workspace, string $namespace, string $key, ?CarbonInterface $expiresAt = null): bool
    {
        return DB::transaction(fn () => $this->create($workspace, $namespace, $key, 'true', $expiresAt), 3);
    }

    /**
     * Remove what has expired, and what belongs to a workflow that no longer
     * exists.
     */
    public function prune(): int
    {
        $expired = StoreEntry::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
        $orphaned = StoreEntry::query()->where('namespace', '!=', '')->whereNotExists(
            fn ($query) => $query->selectRaw('1')->from('workflows')->whereColumn('workflows.id', 'store_entries.namespace')
        )->delete();

        return $expired + $orphaned;
    }

    /**
     * Create the key with this JSON value unless it is live already.
     *
     * @return bool whether it was created
     */
    protected function create(Workspace $workspace, string $namespace, string $key, string $json, ?CarbonInterface $expiresAt): bool
    {
        $this->entries($workspace, $namespace, $key)->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();

        $created = StoreEntry::query()->insertOrIgnore([[
            'workspace_id' => $workspace->id, 'namespace' => $namespace, 'key' => $key, 'value' => $json,
            'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ]]) === 1;

        if ($created) {
            $this->assertRoom($workspace);
        }

        return $created;
    }

    /**
     * @return Builder<StoreEntry>
     */
    protected function entries(Workspace $workspace, string $namespace, string $key): Builder
    {
        return StoreEntry::query()->where(['workspace_id' => $workspace->id, 'namespace' => $namespace, 'key' => $key]);
    }

    /**
     * @param  int  $adding  how many entries are about to be added, not yet counted
     *
     * @throws RuntimeException when that many more entries don't fit
     */
    protected function assertRoom(Workspace $workspace, int $adding = 0): void
    {
        $limit = (int) config('workflows.store.max_entries');

        if (StoreEntry::query()->where('workspace_id', $workspace->id)->live()->count() + $adding > $limit) {
            throw new RuntimeException(__('nodes.store.store_is_full', ['max' => number_format($limit)]));
        }
    }

    /**
     * @throws RuntimeException when the value can't be saved as JSON or is too large
     */
    protected function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException(__('nodes.store.not_json', ['error' => json_last_error_msg()]));
        }

        $max = (int) config('workflows.store.max_value_bytes');

        if (strlen($json) > $max) {
            throw new RuntimeException(__('nodes.store.value_too_large', ['max' => round($max / 1024).' KB']));
        }

        return $json;
    }
}
