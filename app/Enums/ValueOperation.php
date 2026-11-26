<?php

namespace App\Enums;

use RuntimeException;

/**
 * How a step changes a typed value: Set replaces it, Append adds to the
 * end of a String or an Array, and Increment adds to a Number.
 */
enum ValueOperation: string
{
    case Set = 'set';
    case Append = 'append';
    case Increment = 'increment';

    public function label(): string
    {
        return match ($this) {
            self::Set => __('enums.value_operation.set'),
            self::Append => __('enums.value_operation.append'),
            self::Increment => __('enums.value_operation.increment'),
        };
    }

    public function fits(ValueType $type): bool
    {
        return match ($this) {
            self::Set => true,
            self::Append => in_array($type, [ValueType::String, ValueType::Array], true),
            self::Increment => $type === ValueType::Number,
        };
    }

    /**
     * The value after this operation. A value that isn't there yet (null)
     * starts as empty text, an empty array, or 0. Append adds a whole
     * array to an Array as one item, and Increment adds 1 when no amount
     * is given.
     *
     * @throws RuntimeException when the operation doesn't fit the type, or the operand can't be used
     */
    public function apply(ValueType $type, mixed $current, mixed $operand): mixed
    {
        if (! $this->fits($type)) {
            throw new RuntimeException(__('engine.values.operation_does_not_fit', ['operation' => $this->label(), 'type' => $type->label()]));
        }

        if ($this === self::Increment) {
            $total = ($current ?? 0) + ValueType::Number->convert($operand === null || $operand === '' ? 1 : $operand);

            if (is_float($total) && ! is_finite($total)) {
                throw new RuntimeException(__('engine.values.number_too_large'));
            }

            return $total;
        }

        if ($this === self::Append && $type === ValueType::Array) {
            if ($operand === null) {
                throw new RuntimeException(__('engine.values.nothing_to_append'));
            }

            return [...array_values((array) ($current ?? [])), $operand];
        }

        if ($this === self::Append) {
            return ($current ?? '').ValueType::String->convert($operand);
        }

        return $type->convert($operand);
    }
}
