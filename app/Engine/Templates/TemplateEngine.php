<?php

namespace App\Engine\Templates;

/**
 * Renders templated node inputs and evaluates expressions against a run's
 * data. Stage 3 binds a sandboxed Twig and ExpressionLanguage implementation.
 */
interface TemplateEngine
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): mixed;

    /**
     * @param  array<string, mixed>  $data
     */
    public function evaluate(string $expression, array $data): mixed;
}
