<?php

namespace App\Engine\Templates;

/**
 * Renders templated node inputs ({{ expression }} placeholders in text) and
 * evaluates expressions against a run's data.
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
