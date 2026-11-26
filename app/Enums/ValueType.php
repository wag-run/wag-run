<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * The type of a value that is declared up front, such as a data store
 * entry. A value given to it is converted when the conversion is exact
 * ("42" to 42, "true" to true, ISO 8601 text to a date and time, JSON text
 * to an object or array, a number or boolean to text) and refused otherwise.
 */
enum ValueType: string
{
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Object = 'object';
    case Array = 'array';
    case DateTime = 'datetime';

    public function label(): string
    {
        return match ($this) {
            self::String => __('enums.value_type.string'),
            self::Number => __('enums.value_type.number'),
            self::Boolean => __('enums.value_type.boolean'),
            self::Object => __('enums.value_type.object'),
            self::Array => __('enums.value_type.array'),
            self::DateTime => __('enums.value_type.datetime'),
        };
    }

    /**
     * The type as the data picker and previews name it.
     */
    public function pickerType(): string
    {
        return $this === self::DateTime ? 'string' : $this->value;
    }

    /**
     * What a value of this type is before anything is set: empty text, 0,
     * false, an empty object or array, or no date.
     */
    public function emptyValue(): mixed
    {
        return match ($this) {
            self::String => '',
            self::Number => 0,
            self::Boolean => false,
            self::Object, self::Array => [],
            self::DateTime => null,
        };
    }

    /**
     * @return list<ValueOperation>
     */
    public function operations(): array
    {
        return array_values(array_filter(ValueOperation::cases(), fn (ValueOperation $operation) => $operation->fits($this)));
    }

    /**
     * The value as this type, converted when that loses nothing.
     *
     * @throws RuntimeException when the value is not of this type and has no exact conversion
     */
    public function convert(mixed $value): mixed
    {
        $converted = match ($this) {
            self::String => match (true) {
                is_string($value) => $value,
                is_int($value), is_float($value) && is_finite($value) => (string) json_encode($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => null,
            },
            self::Number => match (true) {
                is_int($value), is_float($value) && is_finite($value) => $value,
                is_string($value) && preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', trim($value)) === 1 && is_finite(trim($value) + 0) => trim($value) + 0,
                default => null,
            },
            self::Boolean => match (true) {
                is_bool($value) => $value,
                is_string($value) && in_array(strtolower(trim($value)), ['true', 'false'], true) => strtolower(trim($value)) === 'true',
                default => null,
            },
            self::Object => $this->structure($value, list: false),
            self::Array => $this->structure($value, list: true),
            self::DateTime => $this->dateTime($value),
        };

        if ($converted === null) {
            throw new RuntimeException(__('engine.values.not_a_type', ['type' => $this->label(), 'given' => self::describe($value)]));
        }

        return $converted;
    }

    /**
     * A short description of a value for an error message, such as
     * text "abc" or an array.
     */
    public static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => __('engine.values.given_nothing'),
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) json_encode($value),
            is_string($value) => __('engine.values.given_text', ['text' => mb_strlen($value) > 40 ? mb_substr($value, 0, 40).'…' : $value]),
            is_array($value) && array_is_list($value) => __('engine.values.given_array'),
            default => __('engine.values.given_object'),
        };
    }

    /**
     * An object or an array, as given or from JSON text. An empty one is both.
     *
     * @return array<array-key, mixed>|null
     */
    private function structure(mixed $value, bool $list): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return null;
        }

        return $value === [] || array_is_list($value) === $list ? $value : null;
    }

    /**
     * A date and time as ISO 8601 text, keeping its UTC offset. Text without
     * an offset is read as UTC.
     */
    private function dateTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?\s?(Z|[+-]\d{2}(:?\d{2})?)?$/i', trim($value)) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), 'UTC')->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
