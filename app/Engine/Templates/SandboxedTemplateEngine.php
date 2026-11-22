<?php

namespace App\Engine\Templates;

use Illuminate\Support\Str;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Throwable;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Templating with Twig in sandbox mode, and expressions with Symfony
 * ExpressionLanguage. Templates can use a fixed set of tags, filters and
 * functions and cannot call methods or read properties of PHP objects.
 *
 * A template that is a single {{ expression }} returns the value itself, so
 * "{{ nodes.fetch.output.body.items }}" yields an array, not a string.
 */
class SandboxedTemplateEngine implements TemplateEngine
{
    public const ALLOWED_TAGS = ['if', 'for', 'set', 'apply'];

    public const ALLOWED_FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default', 'escape', 'e', 'filter', 'first',
        'format', 'join', 'json_encode', 'keys', 'last', 'length', 'lower', 'map', 'merge', 'nl2br', 'number_format',
        'raw', 'reduce', 'replace', 'reverse', 'round', 'slice', 'sort', 'split', 'striptags', 'title', 'trim',
        'upper', 'url_encode', 'json_decode', 'base64_encode', 'base64_decode', 'sha256', 'slug',
    ];

    public const ALLOWED_FUNCTIONS = ['range', 'max', 'min', 'date', 'cycle', 'random', 'now', '_capture'];

    public const ALLOWED_TESTS = ['defined', 'empty', 'even', 'odd', 'iterable', 'null', 'none', 'same as', 'divisible by'];

    protected ?Environment $twig = null;

    protected mixed $captured = null;

    public function __construct(protected SafeExpressionLanguage $expressions) {}

    public function render(string $template, array $data): mixed
    {
        if (! str_contains($template, '{{') && ! str_contains($template, '{%') && ! str_contains($template, '{#')) {
            return $template;
        }

        try {
            if (preg_match('/^\s*\{\{(?<expr>(?:(?!\{\{|\}\}|\{%).)+)\}\}\s*$/s', $template, $matches)) {
                $this->captured = null;
                $this->twig()->createTemplate('{{ _capture(('.$matches['expr'].')) }}')->render($data);

                return $this->captured;
            }

            return $this->twig()->createTemplate($template)->render($data);
        } catch (Throwable $e) {
            throw new TemplateError(__('engine.template_error_message', ['message' => $e->getMessage()]), previous: $e);
        }
    }

    public function evaluate(string $expression, array $data): mixed
    {
        try {
            $variables = array_map(DataBag::wrap(...), $data);

            return DataBag::unwrap($this->expressions->evaluate($expression, $variables));
        } catch (SyntaxError $e) {
            throw new TemplateError(__('engine.expression_error_message', ['message' => $e->getMessage()]), previous: $e);
        } catch (Throwable $e) {
            throw new TemplateError(__('engine.expression_error_message', ['message' => $e->getMessage()]), previous: $e);
        }
    }

    protected function twig(): Environment
    {
        if ($this->twig) {
            return $this->twig;
        }

        $twig = new Environment(new ArrayLoader, [
            'autoescape' => false,
            'strict_variables' => false,
            'cache' => false,
        ]);

        $twig->addFilter(new TwigFilter('json_decode', fn (mixed $value) => json_decode((string) $value, true)));
        $twig->addFilter(new TwigFilter('base64_encode', fn (mixed $value) => base64_encode((string) $value)));
        $twig->addFilter(new TwigFilter('base64_decode', fn (mixed $value) => base64_decode((string) $value, true)));
        $twig->addFilter(new TwigFilter('sha256', fn (mixed $value) => hash('sha256', (string) $value)));
        $twig->addFilter(new TwigFilter('slug', fn (mixed $value) => Str::slug((string) $value)));
        $twig->addFunction(new TwigFunction('now', fn () => now()->toIso8601String()));
        $twig->addFunction(new TwigFunction('_capture', function (mixed $value) {
            $this->captured = $value;

            return '';
        }));

        $twig->addExtension(new SandboxExtension(
            new SecurityPolicy(self::ALLOWED_TAGS, self::ALLOWED_FILTERS, [], [], self::ALLOWED_FUNCTIONS, self::ALLOWED_TESTS),
            sandboxed: true,
        ));

        return $this->twig = $twig;
    }
}
