<?php

namespace App\Engine\Templates;

/**
 * The helper functions placeholders can call, grouped the way the docs
 * group them, with how each is called. The data picker lists them, so a
 * helper added to SafeExpressionLanguage belongs here too.
 */
class HelperFunctions
{
    /**
     * How each helper is called, by category.
     *
     * @var array<string, array<string, string>>
     */
    public const SIGNATURES = [
        'text' => [
            'lower' => 'lower(text)',
            'upper' => 'upper(text)',
            'capitalize' => 'capitalize(text)',
            'title' => 'title(text)',
            'trim' => 'trim(text)',
            'replace' => 'replace(text, search, with)',
            'split' => 'split(text, separator, limit)',
            'slug' => 'slug(text)',
            'format' => 'format(pattern, values…)',
            'escape' => 'escape(text)',
            'strip_tags' => 'strip_tags(text)',
            'url_encode' => 'url_encode(value)',
        ],
        'numbers' => [
            'int' => 'int(value)',
            'float' => 'float(value)',
            'round' => 'round(number, decimals)',
            'abs' => 'abs(number)',
            'min' => 'min(a, b, …)',
            'max' => 'max(a, b, …)',
            'number_format' => 'number_format(number, decimals, point, thousands)',
        ],
        'lists' => [
            'length' => 'length(value)',
            'first' => 'first(list)',
            'last' => 'last(list)',
            'slice' => 'slice(list, start, length)',
            'join' => 'join(list, glue)',
            'column' => 'column(list, key)',
            'sum' => 'sum(list)',
            'sort' => 'sort(list)',
            'reverse' => 'reverse(list)',
            'unique' => 'unique(list)',
            'merge' => 'merge(a, b)',
            'keys' => 'keys(object)',
            'values' => 'values(object)',
            'empty' => 'empty(value)',
            'default' => 'default(value, fallback)',
        ],
        'dates' => [
            'now' => 'now()',
            'date' => 'date(value, format, timezone)',
            'add_days' => 'add_days(value, days)',
            'timestamp' => 'timestamp(value)',
        ],
        'encoding' => [
            'json_encode' => 'json_encode(value)',
            'json_decode' => 'json_decode(text)',
            'string' => 'string(value)',
            'base64_encode' => 'base64_encode(text)',
            'base64_decode' => 'base64_decode(text)',
            'sha256' => 'sha256(text)',
        ],
        'files' => [
            'text' => 'text(file)',
            'base64' => 'base64(file)',
        ],
    ];

    /**
     * The helpers for the data picker, by category, each with its name,
     * how it is called and what it does.
     *
     * @return list<array{label: string, items: list<array{name: string, signature: string, description: string}>}>
     */
    public static function catalog(): array
    {
        $groups = [];

        foreach (self::SIGNATURES as $category => $functions) {
            $items = [];

            foreach ($functions as $name => $signature) {
                $items[] = ['name' => $name, 'signature' => $signature, 'description' => __("engine.functions.{$name}")];
            }

            $groups[] = ['label' => __("engine.function_categories.{$category}"), 'items' => $items];
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_merge(...array_values(array_map(array_keys(...), self::SIGNATURES)));
    }
}
