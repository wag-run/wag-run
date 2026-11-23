<?php

namespace App\Engine\Nodes;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use Illuminate\Support\Str;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

/**
 * A sticky note on the canvas. It has no connections and never runs; it is
 * a node only so the editor moves, copies, deletes and undoes it like one.
 * Its text is Markdown, with line breaks kept as typed.
 */
class Note extends Node
{
    public const COLORS = ['yellow', 'blue', 'green', 'pink', 'gray'];

    /**
     * How opaque a note can be, in percent.
     */
    public const OPACITIES = ['100', '75', '50', '25'];

    public const DEFAULT_OPACITY = '50';

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
            new Field('text', __('nodes.note.text'), Field::TEXTAREA, description: __('nodes.note.markdown_works')),
            new Field('color', __('nodes.note.color'), Field::TEXT, default: 'yellow', description: __('nodes.note.a_preset_or_a_hex_code')),
            new Field('opacity', __('nodes.note.opacity'), Field::SELECT, default: self::DEFAULT_OPACITY, options: array_combine(self::OPACITIES, array_map(fn (string $percent) => "{$percent}%", self::OPACITIES))),
        ];
    }

    /**
     * A preset name, or a hex code as #rrggbb (a three-digit code is
     * expanded). Anything else falls back to yellow.
     */
    public static function color(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        if (in_array($value, self::COLORS, true)) {
            return $value;
        }

        if (preg_match('/^#([0-9a-f]{3})$/', $value, $short)) {
            return '#'.implode('', array_map(fn (string $digit) => $digit.$digit, str_split($short[1])));
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : 'yellow';
    }

    /**
     * How a custom (hex) color draws: the fill, an outline a step lighter
     * than it, and text dark or light enough to read on it.
     *
     * @return array{fill: string, line: string, ink: string}
     */
    public static function customColors(string $hex): array
    {
        [$red, $green, $blue] = array_map(fn (string $pair) => hexdec($pair), str_split(substr($hex, 1), 2));
        $lighter = fn (int $channel) => (int) round($channel + (255 - $channel) * 0.55);
        $luminance = (0.2126 * $red + 0.7152 * $green + 0.0722 * $blue) / 255;

        return [
            'fill' => $hex,
            'line' => sprintf('#%02x%02x%02x', $lighter($red), $lighter($green), $lighter($blue)),
            'ink' => $luminance > 0.55 ? '#1c1917' : '#fafaf9',
        ];
    }

    /**
     * The note's text as HTML. Raw HTML in the text is dropped and unsafe
     * links (javascript: and the like) are refused, since workflows are
     * shared; links open in a new tab.
     */
    public static function html(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        return (string) Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br>\n"],
            'external_link' => ['internal_hosts' => [], 'open_in_new_window' => true, 'noopener' => 'external', 'noreferrer' => 'external'],
        ], [new ExternalLinkExtension]);
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
