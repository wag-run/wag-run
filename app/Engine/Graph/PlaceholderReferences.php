<?php

namespace App\Engine\Graph;

use App\Engine\Templates\ExpressionTemplateEngine;
use App\Engine\Templates\SafeExpressionLanguage;
use App\Engine\Templates\TemplateError;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Parser;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Throwable;

/**
 * Finds what the {{ }} placeholders in a value read, by parsing each
 * expression instead of matching text, so "https://store.example.com"
 * outside a placeholder, or "store.x" inside quotes, is not a reference.
 */
class PlaceholderReferences
{
    public function __construct(
        protected ExpressionTemplateEngine $templates,
        protected SafeExpressionLanguage $expressions,
    ) {}

    /**
     * The names read by name under $root, as root.name or root['name'],
     * in a value and everything nested in it. A name chosen by an
     * expression, as in store[trigger.name], can't be known, and a
     * placeholder that can't be parsed is left to fail when it runs.
     *
     * @return list<string>
     */
    public function under(string $root, mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_merge([], ...array_map(fn (mixed $item) => $this->under($root, $item), array_values($value)))));
        }

        if (! is_string($value) || ! str_contains($value, '{{') || ! str_contains($value, $root)) {
            return [];
        }

        $names = [];

        try {
            foreach ($this->templates->expressions($value) as $expression) {
                $this->collectUnder($root, $this->parse($expression), $names);
            }
        } catch (Throwable) {
            return [];
        }

        return array_values(array_unique($names));
    }

    /**
     * Every top-level name a template's placeholders read, such as
     * trigger, nodes, or vars.
     *
     * @return list<string>
     *
     * @throws TemplateError|SyntaxError when a placeholder can't be parsed
     */
    public function roots(string $template): array
    {
        $roots = [];

        foreach ($this->templates->expressions($template) as $expression) {
            $this->collectRoots($this->parse($expression), $roots);
        }

        return array_values(array_unique($roots));
    }

    protected function parse(string $expression): Node
    {
        return $this->expressions->parse($expression, [], Parser::IGNORE_UNKNOWN_VARIABLES | Parser::IGNORE_UNKNOWN_FUNCTIONS)->getNodes();
    }

    /**
     * @param  list<string>  $names
     */
    protected function collectUnder(string $root, Node $node, array &$names): void
    {
        if ($node instanceof GetAttrNode
            && ($node->nodes['node'] ?? null) instanceof NameNode
            && $node->nodes['node']->attributes['name'] === $root
            && ($node->nodes['attribute'] ?? null) instanceof ConstantNode
            && is_scalar($node->nodes['attribute']->attributes['value'])) {
            $names[] = (string) $node->nodes['attribute']->attributes['value'];
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                $this->collectUnder($root, $child, $names);
            }
        }
    }

    /**
     * @param  list<string>  $roots
     */
    protected function collectRoots(Node $node, array &$roots): void
    {
        if ($node instanceof NameNode) {
            $roots[] = (string) $node->attributes['name'];
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                $this->collectRoots($child, $roots);
            }
        }
    }
}
