<?php

namespace Tests\Feature\Engine;

use App\Enums\ValueOperation;
use App\Enums\ValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ValueTypeTest extends TestCase
{
    /**
     * @return array<string, array{ValueType, mixed, mixed}>
     */
    public static function exactConversions(): array
    {
        return [
            'text stays text' => [ValueType::String, 'hello', 'hello'],
            'a number becomes text' => [ValueType::String, 42, '42'],
            'a decimal becomes text' => [ValueType::String, 1.5, '1.5'],
            'a boolean becomes text' => [ValueType::String, true, 'true'],
            'a number stays a number' => [ValueType::Number, 7, 7],
            'whole-number text becomes an integer' => [ValueType::Number, '42', 42],
            'decimal text becomes a float' => [ValueType::Number, ' -1.25 ', -1.25],
            'a boolean stays a boolean' => [ValueType::Boolean, false, false],
            'the text true becomes true' => [ValueType::Boolean, 'true', true],
            'the text False becomes false' => [ValueType::Boolean, 'False', false],
            'an object stays an object' => [ValueType::Object, ['a' => 1], ['a' => 1]],
            'JSON text becomes an object' => [ValueType::Object, '{"a": [1, 2]}', ['a' => [1, 2]]],
            'an empty array is an empty object' => [ValueType::Object, [], []],
            'an array stays an array' => [ValueType::Array, [1, 'two'], [1, 'two']],
            'JSON text becomes an array' => [ValueType::Array, '[1, {"a": 2}]', [1, ['a' => 2]]],
            'ISO text with an offset keeps it' => [ValueType::DateTime, '2026-01-31T09:00:00+02:00', '2026-01-31T09:00:00+02:00'],
            'ISO text ending in Z is UTC' => [ValueType::DateTime, '2026-01-31T09:00:00Z', '2026-01-31T09:00:00+00:00'],
            'a date alone is midnight UTC' => [ValueType::DateTime, '2026-01-31', '2026-01-31T00:00:00+00:00'],
        ];
    }

    #[DataProvider('exactConversions')]
    public function test_converts_a_value_when_the_conversion_is_exact(ValueType $type, mixed $given, mixed $expected): void
    {
        $this->assertSame($expected, $type->convert($given));
    }

    /**
     * @return array<string, array{ValueType, mixed, string}>
     */
    public static function refusedValues(): array
    {
        return [
            'an object as text' => [ValueType::String, ['a' => 1], 'Expected String, but got an object.'],
            'nothing as text' => [ValueType::String, null, 'Expected String, but got nothing.'],
            'words as a number' => [ValueType::Number, 'abc', 'Expected Number, but got the text "abc".'],
            'a number with units' => [ValueType::Number, '42kg', 'Expected Number, but got the text "42kg".'],
            'a boolean as a number' => [ValueType::Number, true, 'Expected Number, but got true.'],
            'empty text as a number' => [ValueType::Number, '', 'Expected Number, but got the text "".'],
            'yes as a boolean' => [ValueType::Boolean, 'yes', 'Expected Boolean, but got the text "yes".'],
            '1 as a boolean' => [ValueType::Boolean, 1, 'Expected Boolean, but got 1.'],
            'an array as an object' => [ValueType::Object, [1, 2], 'Expected Object, but got an array.'],
            'JSON array text as an object' => [ValueType::Object, '[1]', 'Expected Object, but got the text "[1]".'],
            'an object as an array' => [ValueType::Array, ['a' => 1], 'Expected Array, but got an object.'],
            'plain text as an array' => [ValueType::Array, 'a, b', 'Expected Array, but got the text "a, b".'],
            'a loose date' => [ValueType::DateTime, 'tomorrow', 'Expected Date & time, but got the text "tomorrow".'],
            'a date that does not exist' => [ValueType::DateTime, '2026-13-45', 'Expected Date & time, but got the text "2026-13-45".'],
            'a timestamp as a date' => [ValueType::DateTime, 1769850000, 'Expected Date & time, but got 1769850000.'],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_refuses_a_value_that_has_no_exact_conversion(ValueType $type, mixed $given, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $type->convert($given);
    }

    public function test_each_type_offers_only_the_operations_that_fit_it(): void
    {
        $offered = fn (ValueType $type) => array_map(fn (ValueOperation $operation) => $operation->value, $type->operations());

        $this->assertSame(['set', 'append'], $offered(ValueType::String));
        $this->assertSame(['set', 'increment'], $offered(ValueType::Number));
        $this->assertSame(['set', 'append'], $offered(ValueType::Array));
        $this->assertSame(['set'], $offered(ValueType::Boolean));
        $this->assertSame(['set'], $offered(ValueType::Object));
        $this->assertSame(['set'], $offered(ValueType::DateTime));
    }

    public function test_append_and_increment_build_on_the_current_value_or_start_from_empty(): void
    {
        $this->assertSame('ab', ValueOperation::Append->apply(ValueType::String, 'a', 'b'));
        $this->assertSame('7', ValueOperation::Append->apply(ValueType::String, null, 7));
        $this->assertSame([1, [2, 3]], ValueOperation::Append->apply(ValueType::Array, [1], [2, 3]));
        $this->assertSame(['first'], ValueOperation::Append->apply(ValueType::Array, null, 'first'));
        $this->assertSame(5, ValueOperation::Increment->apply(ValueType::Number, 3, '2'));
        $this->assertSame(1, ValueOperation::Increment->apply(ValueType::Number, null, null));
        $this->assertSame(-0.5, ValueOperation::Increment->apply(ValueType::Number, 0, -0.5));
    }

    public function test_an_operation_that_does_not_fit_the_type_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Increment doesn't work on String.");

        ValueOperation::Increment->apply(ValueType::String, 'a', 1);
    }
}
