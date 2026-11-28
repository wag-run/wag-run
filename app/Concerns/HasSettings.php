<?php

namespace App\Concerns;

/**
 * Keeps a model's settings in its `settings` JSON column while they read and
 * write like attributes (`$workspace->retention_days`), so adding a setting
 * needs no migration. The using model declares a `SETTINGS` constant mapping
 * each setting's name to its cast (`bool`, `int`, or `array`) and default, and
 * lists the names in its Fillable attribute:
 *
 *     public const SETTINGS = ['retention_days' => ['int', null]];
 *
 * @property array<string, mixed>|null $settings
 */
trait HasSettings
{
    public function initializeHasSettings(): void
    {
        $this->mergeCasts(['settings' => 'array']);
    }

    public function getAttribute($key)
    {
        if (! array_key_exists($key, static::SETTINGS)) {
            return parent::getAttribute($key);
        }

        [$cast, $default] = static::SETTINGS[$key];
        $settings = $this->settings ?? [];
        $value = array_key_exists($key, $settings) ? $settings[$key] : $default;

        return match (true) {
            $value === null => null,
            $cast === 'bool' => (bool) $value,
            $cast === 'int' => (int) $value,
            $cast === 'string' => (string) $value,
            default => (array) $value,
        };
    }

    public function setAttribute($key, $value)
    {
        if (! array_key_exists($key, static::SETTINGS)) {
            return parent::setAttribute($key, $value);
        }

        $settings = $this->settings ?? [];

        if (! array_key_exists($key, $settings) && $value === static::SETTINGS[$key][1]) {
            return $this;
        }

        return parent::setAttribute('settings', [...$settings, $key => $value]);
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
}
