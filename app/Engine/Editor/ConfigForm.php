<?php

namespace App\Engine\Editor;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use Illuminate\Support\Str;

/**
 * Converts a node's saved config to the shape the editor's form edits, and
 * back. Key-value fields become rows in the form; inside list rows they
 * become "value=Label" lines. Plain number fields are stored as numbers.
 * Fields hidden by another field's value are not saved, and a select
 * whose choice no longer fits its sibling fields goes back to its default.
 */
class ConfigForm
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function toForm(Node $node, array $config): array
    {
        $form = [];

        foreach ($node->inputs() as $field) {
            $form[$field->name] = static::valueToForm($field, $config[$field->name] ?? $field->default);
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, string>  $facts  extra sibling values, see {@see FieldFacts}
     * @return array<string, mixed>
     */
    public static function toConfig(Node $node, array $form, array $facts = []): array
    {
        $config = [];
        $inputs = $node->inputs();

        foreach ($inputs as $field) {
            if ($field->optionsWhen !== [] && ! array_key_exists((string) ($form[$field->name] ?? ''), $field->optionsFor([...$form, ...$facts], $inputs))) {
                $form[$field->name] = $field->default;
            }
        }

        foreach ($inputs as $field) {
            if (! $field->isVisible([...$form, ...$facts], $inputs)) {
                continue;
            }

            $value = static::valueToConfig($field, $form[$field->name] ?? null);

            if ($value !== null && $value !== '' && $value !== []) {
                $config[$field->name] = $value;
            }
        }

        return $config;
    }

    protected static function valueToForm(Field $field, mixed $value): mixed
    {
        return match ($field->type) {
            Field::KEY_VALUE => array_map(
                fn ($key, $item) => ['key' => (string) $key, 'value' => is_scalar($item) || $item === null ? (string) $item : json_encode($item)],
                array_keys((array) $value),
                array_values((array) $value),
            ),
            Field::LIST, Field::SCHEMA => array_values(array_map(
                fn ($row) => static::rowToForm($field, (array) $row),
                array_filter((array) $value, 'is_array'),
            )),
            Field::BOOLEAN => (bool) $value,
            Field::USERS => array_values(array_filter((array) $value, fn (mixed $id) => is_string($id) && Str::isUuid($id))),
            default => is_array($value) ? json_encode($value) : ($value === null ? '' : (string) $value),
        };
    }

    protected static function valueToConfig(Field $field, mixed $value): mixed
    {
        return match ($field->type) {
            Field::KEY_VALUE => collect((array) $value)
                ->filter(fn ($row) => is_array($row) && trim((string) ($row['key'] ?? '')) !== '')
                ->mapWithKeys(fn ($row) => [trim((string) $row['key']) => (string) ($row['value'] ?? '')])
                ->all(),
            Field::LIST, Field::SCHEMA => array_values(array_map(
                fn ($row) => static::rowToConfig($field, (array) $row),
                array_filter((array) $value, 'is_array'),
            )),
            Field::BOOLEAN => (bool) $value,
            Field::NUMBER => $field->templated && ! is_numeric($value) ? (string) $value : (is_numeric($value) ? $value + 0 : null),
            Field::WORKFLOW => is_string($value) && Str::isUuid($value) ? $value : null,
            Field::USER => is_string($value) && Str::isUuid($value) ? $value : null,
            Field::USERS => array_values(array_unique(array_filter((array) $value, fn (mixed $id) => is_string($id) && Str::isUuid($id)))),
            default => is_string($value) ? $value : null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function rowToForm(Field $field, array $row): array
    {
        $form = [];

        foreach ($field->fields as $sub) {
            $value = $row[$sub->name] ?? $sub->default;
            $form[$sub->name] = $sub->type === Field::KEY_VALUE
                ? collect((array) $value)->map(fn ($label, $key) => "{$key}={$label}")->implode("\n")
                : static::valueToForm($sub, $value);
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function rowToConfig(Field $field, array $row): array
    {
        $config = [];

        foreach ($field->fields as $sub) {
            if (! $sub->isVisible($row, $field->fields)) {
                continue;
            }

            $value = $row[$sub->name] ?? null;

            if ($sub->type === Field::KEY_VALUE) {
                $value = collect(preg_split('/\R/', (string) $value) ?: [])
                    ->map(fn (string $line) => array_map('trim', explode('=', $line, 2)))
                    ->filter(fn (array $pair) => $pair[0] !== '')
                    ->mapWithKeys(fn (array $pair) => [$pair[0] => $pair[1] ?? $pair[0]])
                    ->all();
            } else {
                $value = static::valueToConfig($sub, $value);
            }

            if ($value !== null && $value !== '' && $value !== []) {
                $config[$sub->name] = $value;
            }
        }

        return $config;
    }
}
