<?php

namespace App\Engine;

use App\Enums\NodeKind;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The node types this install offers. Built-in nodes and custom integrations
 * register the same way, from a service provider:
 *
 *     $this->app->make(NodeRegistry::class)->register(MyNode::class);
 */
class NodeRegistry
{
    /**
     * @var array<string, class-string<Node>>
     */
    protected array $types = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  class-string<Node>  $class
     */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, Node::class)) {
            throw new InvalidArgumentException("[{$class}] must extend ".Node::class.'.');
        }

        $this->types[$class::type()] = $class;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function make(string $type): Node
    {
        $class = $this->types[$type] ?? throw new InvalidArgumentException("Unknown node type [{$type}].");

        return $this->container->make($class);
    }

    /**
     * @return array<string, Node>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => $this->container->make($class), $this->types);
    }

    /**
     * @return array<string, Node>
     */
    public function ofKind(NodeKind $kind): array
    {
        return array_filter($this->all(), fn (Node $node) => $node->kind() === $kind);
    }
}
