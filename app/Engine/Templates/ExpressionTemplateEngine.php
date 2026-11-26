<?php

namespace App\Engine\Templates;

use App\Services\WorkflowFiles;
use Throwable;

/**
 * One language for every field: expressions are Symfony ExpressionLanguage
 * (with the safe helper functions), and a templated field is text with
 * {{ expression }} placeholders.
 *
 * A template that is a single {{ expression }} returns the value itself, so
 * "{{ nodes.fetch.output.body.items }}" yields an array, not a string. In
 * longer text, null becomes empty, true and false become words and lists or
 * objects become JSON.
 */
class ExpressionTemplateEngine implements TemplateEngine
{
    public function __construct(protected SafeExpressionLanguage $expressions) {}

    public function render(string $template, array $data): mixed
    {
        if (! str_contains($template, '{{')) {
            return $template;
        }

        $parts = $this->split($template);

        if (count($parts) === 1 && $parts[0][0] === 'expression') {
            return $this->evaluate($parts[0][1], $data);
        }

        $text = '';

        foreach ($parts as [$kind, $value]) {
            $text .= $kind === 'text' ? $value : $this->stringify($this->evaluate($value, $data));
        }

        return $text;
    }

    public function evaluate(string $expression, array $data): mixed
    {
        try {
            $variables = array_map(DataBag::wrap(...), $data);

            return DataBag::unwrap($this->expressions->evaluate($expression, $variables));
        } catch (TemplateError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TemplateError(__('engine.expression_error_message', ['message' => $e->getMessage()]), previous: $e);
        }
    }

    /**
     * The expressions inside a template's {{ }} placeholders.
     *
     * @return list<string>
     *
     * @throws TemplateError when a placeholder is not closed
     */
    public function expressions(string $template): array
    {
        return array_column(array_filter($this->split($template), fn (array $part) => $part[0] === 'expression'), 1);
    }

    /**
     * Split a template into text and the expressions inside {{ }}. Quotes and
     * braces inside an expression are respected, so {{ {a: 1}.a }} and
     * {{ "}}" }} work. A template that is one placeholder, give or take
     * surrounding whitespace, comes back as a single expression part.
     *
     * @return list<array{'text'|'expression', string}>
     */
    protected function split(string $template): array
    {
        $parts = [];
        $length = strlen($template);
        $offset = 0;

        while (($start = strpos($template, '{{', $offset)) !== false) {
            $end = $this->closingBraces($template, $start + 2, $length);

            if ($end === null) {
                throw new TemplateError(__('engine.template_error_message', ['message' => __('engine.unclosed_placeholder')]));
            }

            if ($start > $offset) {
                $parts[] = ['text', substr($template, $offset, $start - $offset)];
            }

            $parts[] = ['expression', trim(substr($template, $start + 2, $end - $start - 2))];
            $offset = $end + 2;
        }

        if ($offset < $length) {
            $parts[] = ['text', substr($template, $offset)];
        }

        $expressions = array_values(array_filter($parts, fn (array $part) => $part[0] === 'expression'));
        $onlyWhitespaceAround = array_filter($parts, fn (array $part) => $part[0] === 'text' && trim($part[1]) !== '') === [];

        return count($expressions) === 1 && $onlyWhitespaceAround ? $expressions : $parts;
    }

    /**
     * The position of the "}}" that closes the placeholder opened before $from.
     */
    protected function closingBraces(string $template, int $from, int $length): ?int
    {
        $quote = null;
        $depth = 0;

        for ($i = $from; $i < $length; $i++) {
            $char = $template[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                if ($depth === 0 && ($template[$i + 1] ?? '') === '}') {
                    return $i;
                }

                $depth = max(0, $depth - 1);
            }
        }

        return null;
    }

    protected function stringify(mixed $value): string
    {
        if (WorkflowFiles::isReference($value)) {
            throw new TemplateError(__('run_files.cannot_be_part_of_text'));
        }

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
