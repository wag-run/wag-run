<?php

namespace App\Engine\Nodes;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * A sticky note on the canvas. It has no connections and never runs; it is
 * a node only so the editor moves, copies, deletes and undoes it like one.
 */
class Note extends Node
{
    public const COLORS = ['yellow', 'blue', 'green', 'pink', 'gray'];

    public static function type(): string
    {
        return 'core.note';
    }

    public function label(): string
    {
        return __('nodes.note.sticky_note');
    }

    public function description(): string
    {
        return __('nodes.note.a_note_on_the_canvas');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Note;
    }

    public function category(): string
    {
        return __('nodes.note.notes');
    }

    public function icon(): string
    {
        return 'pencil-square';
    }

    public function inputs(): array
    {
        return [
            new Field('text', __('nodes.note.text'), Field::TEXTAREA),
            new Field('color', __('nodes.note.color'), Field::SELECT, default: 'yellow', options: array_combine(self::COLORS, array_map(fn (string $color) => __("nodes.note.{$color}"), self::COLORS))),
        ];
    }

    public function ports(array $config = []): array
    {
        return [];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete();
    }
}
