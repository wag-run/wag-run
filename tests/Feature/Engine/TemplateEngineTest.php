<?php

namespace Tests\Feature\Engine;

use App\Engine\Templates\HelperFunctions;
use App\Engine\Templates\SafeExpressionLanguage;
use App\Engine\Templates\TemplateEngine;
use App\Engine\Templates\TemplateError;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'trigger' => ['values' => ['name' => 'ada', 'qty' => 3]],
            'nodes' => ['fetch' => ['output' => ['status' => 200, 'body' => ['items' => [['id' => 1], ['id' => 2]], 'tags' => ['vip', 'new']]]]],
            'vars' => ['region' => 'eu'],
            'item' => ['price' => 9.5],
        ];
    }

    private function engine(): TemplateEngine
    {
        return app(TemplateEngine::class);
    }

    public function test_fills_placeholders_in_text_with_expressions_and_helpers(): void
    {
        $this->assertSame(
            'Hello Ada, 2 items in eu: 1,2 (VIP)',
            $this->engine()->render("Hello {{ capitalize(trigger.values.name) }}, {{ length(nodes.fetch.output.body.items) }} items in {{ vars.region }}: {{ join(column(nodes.fetch.output.body.items, 'id'), ',') }} ({{ 'vip' in nodes.fetch.output.body.tags ? 'VIP' : 'standard' }})", $this->data()),
        );
    }

    public function test_placeholders_in_text_print_null_as_nothing_booleans_as_words_and_data_as_json(): void
    {
        $this->assertSame(
            '[] [true] [["vip","new"]] [none]',
            $this->engine()->render("[{{ nodes.skipped.output.id }}] [{{ trigger.values.qty > 2 }}] [{{ nodes.fetch.output.body.tags }}] [{{ trigger.values.missing ?? 'none' }}]", $this->data()),
        );
    }

    public function test_a_single_expression_template_returns_the_value_itself(): void
    {
        $this->assertSame([['id' => 1], ['id' => 2]], $this->engine()->render('{{ nodes.fetch.output.body.items }}', $this->data()));
        $this->assertSame(28.5, $this->engine()->render(' {{ item.price * trigger.values.qty }} ', $this->data()));
        $this->assertNull($this->engine()->render('{{ nodes.missing.output }}', $this->data()));
    }

    public function test_quotes_and_braces_inside_a_placeholder_do_not_end_it(): void
    {
        $this->assertSame('a }} b {"x":1}', $this->engine()->render("a {{ '}}' }} b {{ {x: 1} }}", $this->data()));
    }

    public function test_text_without_placeholders_is_returned_untouched(): void
    {
        $this->assertSame('plain {text} {% if x %}', $this->engine()->render('plain {text} {% if x %}', $this->data()));
    }

    #[TestWith(['Hello {{ trigger.values.name'])]
    #[TestWith(['{{ constant("PHP_VERSION") }}'])]
    #[TestWith(['{{ trigger.values.name|upper }}'])]
    public function test_refuses_unclosed_placeholders_and_expressions_it_cannot_run(string $template): void
    {
        $this->expectException(TemplateError::class);

        $this->engine()->render($template, $this->data());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function helpers(): array
    {
        return [
            'title' => ["title('ada lovelace')", 'Ada Lovelace'],
            'replace' => ["replace('a-b-c', '-', '+')", 'a+b+c'],
            'split' => ["split('a,b', ',')", ['a', 'b']],
            'first item' => ['first(nodes.fetch.output.body.items).id', 1],
            'last item' => ['last(nodes.fetch.output.body.tags)', 'new'],
            'slice' => ['slice(nodes.fetch.output.body.tags, 1)', ['new']],
            'sum' => ["sum(column(nodes.fetch.output.body.items, 'id'))", 3],
            'default for empty' => ["default('', 'n/a')", 'n/a'],
            'date' => ["date('2026-11-22T10:00:00Z', 'j M Y')", '22 Nov 2026'],
            'number format' => ['number_format(1234.5, 2)', '1,234.50'],
            'format' => ["format('%s has %d', trigger.values.name, trigger.values.qty)", 'ada has 3'],
            'url encode' => ["url_encode({q: 'a b', page: 2})", 'q=a%20b&page=2'],
            'escape' => ["escape('<b>')", '&lt;b&gt;'],
        ];
    }

    #[DataProvider('helpers')]
    public function test_helper_functions(string $expression, mixed $expected): void
    {
        $this->assertSame($expected, $this->engine()->evaluate($expression, $this->data()));
    }

    public function test_every_helper_function_is_listed_for_the_data_picker_with_a_description(): void
    {
        $catalog = HelperFunctions::catalog();

        $this->assertEqualsCanonicalizing(app(SafeExpressionLanguage::class)->helperNames(), HelperFunctions::names());
        foreach (array_merge(...array_column($catalog, 'items')) as $function) {
            $this->assertStringNotContainsString('engine.functions.', $function['description'], "{$function['name']} has no description");
        }
    }

    public function test_evaluates_expressions_with_dot_and_bracket_access(): void
    {
        $engine = $this->engine();

        $this->assertTrue($engine->evaluate('nodes.fetch.output.status == 200 and trigger.values.qty > 2', $this->data()));
        $this->assertTrue($engine->evaluate("'vip' in nodes['fetch']['output']['body']['tags']", $this->data()));
        $this->assertSame(2, $engine->evaluate('length(nodes.fetch.output.body.items)', $this->data()));
        $this->assertSame([['id' => 1], ['id' => 2]], $engine->evaluate('nodes.fetch.output.body.items', $this->data()));
        $this->assertNull($engine->evaluate('nodes.fetch.output.nope', $this->data()));
        $this->assertSame('EU', $engine->evaluate('upper(vars.region)', $this->data()));
    }

    #[TestWith(['upper()', 'Expression error: upper() needs 1 argument, but got 0.'])]
    #[TestWith(['replace("a")', 'Expression error: replace() needs 2 arguments, but got 1.'])]
    #[TestWith(['format([1])', 'Expression error: format() needs string for format, but got array.'])]
    public function test_a_helper_called_with_the_wrong_arguments_says_so_in_words(string $expression, string $message): void
    {
        $this->expectExceptionObject(new TemplateError($message));

        $this->engine()->evaluate($expression, $this->data());
    }

    #[TestWith(['constant("PHP_VERSION")'])]
    #[TestWith(['enum("App\\\\Enums\\\\StepStatus::Pending")'])]
    #[TestWith(['nodes.fetch.output.status =='])]
    public function test_rejects_unsafe_or_invalid_expressions(string $expression): void
    {
        $this->expectException(TemplateError::class);

        $this->engine()->evaluate($expression, $this->data());
    }

    #[TestWith(['nodes.count()', 'count'])]
    #[TestWith(['nodes.fetch.output.getIterator()', 'getIterator'])]
    #[TestWith(['nodes?.count()', 'count'])]
    #[TestWith(['length(trigger.values.offsetGet("name"))', 'offsetGet'])]
    #[TestWith(['true ? 1 : nodes.count()', 'count'])]
    public function test_refuses_an_expression_that_calls_a_method_even_where_it_would_not_run(string $expression, string $method): void
    {
        $this->expectExceptionObject(new TemplateError("Expression error: an expression cannot call a method, and this one calls {$method}(). Use a helper function instead."));

        $this->engine()->evaluate($expression, $this->data());
    }
}
