<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Outputs its "value" input, plus what it could see in the run's data.
 */
class Emit extends Node
{
    public static function type(): string
    {
        return 'test.emit';
    }

    public function label(): string
    {
        return 'Emit';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function inputs(): array
    {
        return [new Field('value', 'Value', templated: true)];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $data = $context->data();

        return NodeResult::complete([
            'value' => $context->input('value'),
            'item' => $data['item'] ?? null,
            'saw' => array_keys($data['nodes']),
            'error' => $data['error']['message'] ?? null,
        ]);
    }
}
