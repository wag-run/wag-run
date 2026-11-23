<?php

namespace App\Engine\Templates;

use App\Services\WorkflowFiles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\ParsedExpression;
use Symfony\Component\ExpressionLanguage\Parser;

/**
 * ExpressionLanguage without the built-in constant() and enum() functions,
 * which would let an expression read PHP constants, plus helpers for
 * working with text, lists, numbers and dates. Expressions cannot call
 * methods on PHP objects; run data is wrapped in read-only DataBags.
 */
class SafeExpressionLanguage extends ExpressionLanguage
{
    /**
     * @var list<string>
     */
    protected array $helperNames = [];

    public function __construct()
    {
        parent::__construct(new ArrayAdapter(deepClone: false, maxItems: 500));
    }

    /**
     * Parse as usual, then make every property and item access lenient, so a
     * path into data that is not there (nodes.skipped.output.id) reads as
     * null instead of failing, the way templates have always behaved.
     *
     * @param  array<int|string, string>  $names
     * @param  int-mask-of<Parser::IGNORE_*>  $flags
     */
    public function parse(Expression|string $expression, array $names, int $flags = 0): ParsedExpression
    {
        $parsed = parent::parse($expression, $names, $flags);
        $this->makeLenient($parsed->getNodes());

        return $parsed;
    }

    protected function makeLenient(Node $node): void
    {
        if ($node instanceof GetAttrNode && $node->attributes['type'] !== GetAttrNode::METHOD_CALL) {
            $node->attributes['is_null_coalesce'] = true;
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                $this->makeLenient($child);
            }
        }
    }

    /**
     * The names of the helper functions expressions can call.
     *
     * @return list<string>
     */
    public function helperNames(): array
    {
        return $this->helperNames;
    }

    /**
     * A file's bytes, read for text() or base64(). Only files of the run's
     * workspace can be read, and only small ones.
     */
    protected static function fileBytes(mixed $reference): string
    {
        $files = app(WorkflowFiles::class);

        return $files->contents($files->inScope($reference));
    }

    protected static function fileText(mixed $reference): string
    {
        $bytes = static::fileBytes($reference);

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new RuntimeException(__('run_files.not_text'));
        }

        return $bytes;
    }

    protected static function date(mixed $value = null, ?string $timezone = null): CarbonImmutable
    {
        $date = match (true) {
            $value === null || $value === '' => CarbonImmutable::now(),
            is_numeric($value) => CarbonImmutable::createFromTimestamp((int) $value),
            default => CarbonImmutable::parse((string) $value),
        };

        return $timezone ? $date->setTimezone($timezone) : $date;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<mixed>
     */
    protected static function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }

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
            'date' => fn (mixed $value = null, string $format = 'Y-m-d H:i:s', ?string $timezone = null): string => static::date($value, $timezone)->format($format),
            'add_days' => fn (mixed $value, int|float $days): string => static::date($value)->addDays($days)->toIso8601String(),
            'capitalize' => fn (mixed $value): string => mb_strtoupper(mb_substr((string) $value, 0, 1)).mb_strtolower(mb_substr((string) $value, 1)),
            'title' => fn (mixed $value): string => mb_convert_case((string) $value, MB_CASE_TITLE),
            'replace' => fn (mixed $value, mixed $search, mixed $replacement = ''): string => str_replace((string) $search, (string) $replacement, (string) $value),
            'split' => fn (mixed $value, string $delimiter = ',', ?int $limit = null): array => $delimiter === '' ? mb_str_split((string) $value) : explode($delimiter, (string) $value, $limit ?? PHP_INT_MAX),
            'join' => fn (mixed $value, string $glue = ''): string => implode($glue, array_map(fn (mixed $item) => is_scalar($item) || $item === null ? (string) $item : (string) json_encode($item), (array) DataBag::unwrap($value))),
            'first' => fn (mixed $value): mixed => DataBag::wrap(is_string($value) ? mb_substr($value, 0, 1) : (array_values((array) DataBag::unwrap($value))[0] ?? null)),
            'last' => fn (mixed $value): mixed => DataBag::wrap(is_string($value) ? mb_substr($value, -1) : (array_values((array) DataBag::unwrap($value))[count((array) DataBag::unwrap($value)) - 1] ?? null)),
            'slice' => fn (mixed $value, int $start, ?int $length = null): mixed => is_string($value) ? mb_substr($value, $start, $length) : DataBag::wrap(array_slice((array) DataBag::unwrap($value), $start, $length)),
            'reverse' => fn (mixed $value): mixed => is_string($value) ? implode('', array_reverse(mb_str_split($value))) : DataBag::wrap(array_reverse((array) DataBag::unwrap($value))),
            'sort' => fn (mixed $value): array => DataBag::wrap(static::sorted((array) DataBag::unwrap($value))),
            'unique' => fn (mixed $value): array => DataBag::wrap(array_values(array_unique((array) DataBag::unwrap($value), SORT_REGULAR))),
            'column' => fn (mixed $value, string $key): array => DataBag::wrap(array_column(array_filter((array) DataBag::unwrap($value), 'is_array'), $key)),
            'sum' => fn (mixed $value): int|float => array_sum(array_filter((array) DataBag::unwrap($value), 'is_numeric')),
            'merge' => fn (mixed $first, mixed $second): mixed => DataBag::wrap(array_merge((array) DataBag::unwrap($first), (array) DataBag::unwrap($second))),
            'default' => fn (mixed $value, mixed $fallback): mixed => $value === null || $value === '' || $value === [] ? $fallback : $value,
            'number_format' => fn (mixed $value, int $decimals = 0, string $point = '.', string $thousands = ','): string => number_format((float) $value, $decimals, $point, $thousands),
            'format' => fn (string $format, mixed ...$values): string => vsprintf($format, array_map(fn (mixed $item) => is_scalar($item) || $item === null ? $item : json_encode(DataBag::unwrap($item)), $values)),
            'url_encode' => fn (mixed $value): string => is_scalar($value) || $value === null ? rawurlencode((string) $value) : http_build_query((array) DataBag::unwrap($value), '', '&', PHP_QUERY_RFC3986),
            'escape' => fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'strip_tags' => fn (mixed $value): string => strip_tags((string) $value),
            'base64_encode' => fn (mixed $value): string => base64_encode((string) $value),
            'text' => fn (mixed $value): string => static::fileText(DataBag::unwrap($value)),
            'base64' => fn (mixed $value): string => WorkflowFiles::isReference($file = DataBag::unwrap($value))
                ? base64_encode(static::fileBytes($file))
                : base64_encode(is_scalar($file) || $file === null ? (string) $file : (string) json_encode($file)),
            'base64_decode' => fn (mixed $value): string => (string) base64_decode((string) $value, true),
            'sha256' => fn (mixed $value): string => hash('sha256', (string) $value),
            'slug' => fn (mixed $value): string => Str::slug((string) $value),
        ];

        $this->helperNames = array_keys($helpers);

        foreach ($helpers as $name => $helper) {
            $this->addFunction(new ExpressionFunction(
                $name,
                fn () => throw new TemplateError("Expressions cannot be compiled to PHP ({$name})."),
                fn (array $variables, mixed ...$arguments) => $helper(...$arguments),
            ));
        }
    }
}
