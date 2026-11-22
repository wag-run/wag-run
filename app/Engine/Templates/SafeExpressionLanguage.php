<?php

namespace App\Engine\Templates;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * ExpressionLanguage without the built-in constant() and enum() functions,
 * which would let an expression read PHP constants, plus a small set of
 * helpers for working with data.
 */
class SafeExpressionLanguage extends ExpressionLanguage
{
    protected function registerFunctions(): void
    {
        $helpers = [
            'length' => fn (mixed $value): int => match (true) {
                is_string($value) => mb_strlen($value),
                is_countable($value) => count($value),
                default => 0,
            },
            'lower' => fn (mixed $value): string => mb_strtolower((string) $value),
            'upper' => fn (mixed $value): string => mb_strtoupper((string) $value),
            'trim' => fn (mixed $value): string => trim((string) $value),
            'int' => fn (mixed $value): int => (int) $value,
            'float' => fn (mixed $value): float => (float) $value,
            'string' => fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : (string) json_encode(DataBag::unwrap($value)),
            'round' => fn (mixed $value, int $precision = 0): float => round((float) $value, $precision),
            'abs' => fn (mixed $value): int|float => abs(is_numeric($value) ? $value + 0 : 0),
            'min' => fn (mixed ...$values): mixed => min(...array_map(DataBag::unwrap(...), $values)),
            'max' => fn (mixed ...$values): mixed => max(...array_map(DataBag::unwrap(...), $values)),
            'keys' => fn (mixed $value): array => array_keys((array) DataBag::unwrap($value)),
            'values' => fn (mixed $value): array => array_values((array) DataBag::unwrap($value)),
            'empty' => fn (mixed $value): bool => empty(DataBag::unwrap($value)),
            'json_encode' => fn (mixed $value): string => (string) json_encode(DataBag::unwrap($value)),
            'json_decode' => fn (mixed $value): mixed => DataBag::wrap(json_decode((string) $value, true)),
            'now' => fn (): string => now()->toIso8601String(),
            'timestamp' => fn (mixed $value = null): int => $value === null ? now()->getTimestamp() : (int) strtotime((string) $value),
        ];

        foreach ($helpers as $name => $helper) {
            $this->addFunction(new ExpressionFunction(
                $name,
                fn () => throw new TemplateError("Expressions cannot be compiled to PHP ({$name})."),
                fn (array $variables, mixed ...$arguments) => $helper(...$arguments),
            ));
        }
    }
}
