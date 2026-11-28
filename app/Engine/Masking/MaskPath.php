<?php

namespace App\Engine\Masking;

use InvalidArgumentException;

/**
 * A path into a node's data, as a person writes it for "Always mask" or
 * "Never mask": `body.items[*].secret`. Segments are keys joined with dots,
 * list positions in brackets, `[*]` for any position, and `["a.b"]` for a
 * key that holds a dot or other characters a plain key can't.
 *
 * Paths are relative to what the node produces: its output, or for a
 * trigger, the run's trigger data.
 */
final readonly class MaskPath
{
    /**
     * @param  list<string|int|null>  $segments  null for [*]
     */
    public function __construct(public array $segments) {}

    /**
     * @throws InvalidArgumentException when the text isn't a path
     */
    public static function parse(string $text): self
    {
        $text = trim($text);
        $segments = [];
        $offset = 0;
        $length = strlen($text);

        if ($text === '') {
            throw new InvalidArgumentException(__('engine.masking.path_empty'));
        }

        while ($offset < $length) {
            if (preg_match('/\G\[\*\]/', $text, $match, 0, $offset)) {
                $segments[] = null;
            } elseif (preg_match('/\G\[(\d+)\]/', $text, $match, 0, $offset)) {
                $segments[] = (int) $match[1];
            } elseif (preg_match('/\G\["((?:[^"\\\\]|\\\\.)*)"\]/', $text, $match, 0, $offset)) {
                $segments[] = (string) json_decode('"'.$match[1].'"');
            } elseif (preg_match('/\G\.?([^.\[\]"\s]+)/', $text, $match, 0, $offset) && ($offset > 0 || $match[0][0] !== '.')) {
                $segments[] = $match[1];
            } else {
                throw new InvalidArgumentException(__('engine.masking.path_invalid', ['path' => $text]));
            }

            $offset += strlen($match[0]);
        }

        return new self($segments);
    }

    /**
     * A concrete path, such as one a run masked, written the way parse()
     * reads it.
     *
     * @param  list<string|int|null>  $segments
     */
    public static function format(array $segments): string
    {
        $text = '';

        foreach ($segments as $segment) {
            $text .= match (true) {
                $segment === null => '[*]',
                is_int($segment) => '['.$segment.']',
                (bool) preg_match('/^[A-Za-z0-9_$@-]+$/', $segment) => ($text === '' ? '' : '.').$segment,
                default => '['.json_encode($segment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).']',
            };
        }

        return $text;
    }

    /**
     * The same path with every list position written as [*], so it covers
     * that place in every item.
     *
     * @param  list<string|int|null>  $segments
     */
    public static function wildcard(array $segments): string
    {
        return self::format(array_map(fn (string|int|null $segment) => is_int($segment) ? null : $segment, $segments));
    }

    public function toString(): string
    {
        return self::format($this->segments);
    }

    /**
     * Whether this path names exactly $path.
     *
     * @param  list<string|int>  $path
     */
    public function matches(array $path): bool
    {
        return count($path) === count($this->segments) && $this->leads($path);
    }

    /**
     * Whether $path starts with this path: it is this place or inside it.
     *
     * @param  list<string|int>  $path
     */
    public function leads(array $path): bool
    {
        if (count($this->segments) > count($path)) {
            return false;
        }

        foreach ($this->segments as $index => $segment) {
            if ($segment !== null && (string) $segment !== (string) $path[$index]) {
                return false;
            }
        }

        return true;
    }
}
