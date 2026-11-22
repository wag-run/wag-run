<?php

namespace App\Engine\Templates;

use RuntimeException;

/**
 * A template engine that passes values through untouched.
 */
class PlainTemplateEngine implements TemplateEngine
{
    public function render(string $template, array $data): mixed
    {
        return $template;
    }

    public function evaluate(string $expression, array $data): mixed
    {
        throw new RuntimeException('No expression engine is configured.');
    }
}
