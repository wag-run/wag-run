<?php

namespace Tests\Feature\Engine;

use App\Engine\Templates\TemplateEngine;
use App\Engine\Templates\TemplateError;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TemplateEngineTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return [
            'trigger' => ['name' => 'ada', 'qty' => 3],
            'nodes' => ['fetch' => ['output' => ['status' => 200, 'body' => ['items' => [['id' => 1], ['id' => 2]], 'tags' => ['vip', 'new']]]]],
            'vars' => ['region' => 'eu'],
            'item' => ['price' => 9.5],
        ];
    }

    private function engine(): TemplateEngine
    {
        return app(TemplateEngine::class);
    }

    public function test_renders_twig_templates_with_filters(): void
    {
        $this->assertSame(
            'Hello Ada, 2 items in eu: 1,2',
            $this->engine()->render("Hello {{ trigger.name|capitalize }}, {{ nodes.fetch.output.body.items|length }} items in {{ vars.region }}: {{ nodes.fetch.output.body.items|map(i => i.id)|join(',') }}", $this->data()),
        );
    }

    public function test_a_single_expression_template_returns_the_value_itself(): void
    {
        $this->assertSame([['id' => 1], ['id' => 2]], $this->engine()->render('{{ nodes.fetch.output.body.items }}', $this->data()));
        $this->assertSame(28.5, $this->engine()->render(' {{ item.price * trigger.qty }} ', $this->data()));
        $this->assertNull($this->engine()->render('{{ nodes.missing.output }}', $this->data()));
    }

    public function test_text_without_template_syntax_is_returned_untouched(): void
    {
        $this->assertSame('plain {text}', $this->engine()->render('plain {text}', $this->data()));
    }

    #[TestWith(['{{ include("x") }}'])]
    #[TestWith(['{% include "x" %}'])]
    #[TestWith(['{{ source("x") }}'])]
    #[TestWith(['{% macro m() %}{% endmacro %}'])]
    #[TestWith(['{{ ["system"]|map("passthru") }}'])]
    #[TestWith(['{{ ["id"]|filter("system")|join }}'])]
    public function test_sandbox_refuses_tags_functions_and_callables_outside_the_policy(string $template): void
    {
        $this->expectException(TemplateError::class);

        $this->engine()->render($template, $this->data());
    }

    public function test_evaluates_expressions_with_dot_and_bracket_access(): void
    {
        $engine = $this->engine();

        $this->assertTrue($engine->evaluate('nodes.fetch.output.status == 200 and trigger.qty > 2', $this->data()));
        $this->assertTrue($engine->evaluate("'vip' in nodes['fetch']['output']['body']['tags']", $this->data()));
        $this->assertSame(2, $engine->evaluate('length(nodes.fetch.output.body.items)', $this->data()));
        $this->assertSame([['id' => 1], ['id' => 2]], $engine->evaluate('nodes.fetch.output.body.items', $this->data()));
        $this->assertNull($engine->evaluate('nodes.fetch.output.nope', $this->data()));
        $this->assertSame('EU', $engine->evaluate('upper(vars.region)', $this->data()));
    }

    #[TestWith(['constant("PHP_VERSION")'])]
    #[TestWith(['enum("App\\\\Enums\\\\StepStatus::Pending")'])]
    #[TestWith(['nodes.fetch.output.status =='])]
    public function test_rejects_unsafe_or_invalid_expressions(string $expression): void
    {
        $this->expectException(TemplateError::class);

        $this->engine()->evaluate($expression, $this->data());
    }
}
