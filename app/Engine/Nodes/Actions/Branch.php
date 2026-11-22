<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Splits the flow into branches that all run in parallel.
 */
class Branch extends Node
{
    public static function type(): string
    {
        return 'core.branch';
    }

    public function label(): string
    {
        return __('Branch');
    }

    public function description(): string
    {
        return __('Splits the flow into branches that run in parallel. Every branch runs.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('Flow');
    }

    public function icon(): string
    {
        return 'arrows-pointing-out';
    }

    public function hasErrorPort(): bool
    {
        return false;
    }

    public function inputs(): array
    {
        return [new Field('branches', __('Number of branches'), Field::NUMBER, required: true, default: 2)];
    }

    public function ports(array $config = []): array
    {
        $count = max(2, min(10, (int) ($config['branches'] ?? 2)));

        return array_map(fn (int $i) => "branch_{$i}", range(1, $count));
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete(null, $this->ports($context->config()));
    }
}
