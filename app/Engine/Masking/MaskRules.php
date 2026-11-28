<?php

namespace App\Engine\Masking;

use App\Engine\Graph\GraphNode;
use InvalidArgumentException;

/**
 * A node's own masking settings, from its Masking tab: paths in its output
 * (a trigger's: in the trigger data) to always mask, and paths to never
 * mask by name. "Never mask" turns off name matches only; a value that
 * looks like a secret, or is one the run already knows, is masked anyway.
 */
final readonly class MaskRules
{
    /**
     * @param  list<MaskPath>  $always
     * @param  list<MaskPath>  $never
     */
    public function __construct(public array $always = [], public array $never = []) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * The rules saved on a node. A path that no longer parses is skipped.
     */
    public static function for(?GraphNode $node): self
    {
        if ($node === null) {
            return self::none();
        }

        return new self(self::parsed($node->masking['always'] ?? []), self::parsed($node->masking['never'] ?? []));
    }

    /**
     * Whether an "Always mask" path names $path or a place it is inside.
     *
     * @param  list<string|int>  $path
     */
    public function alwaysMasks(array $path): bool
    {
        return array_any($this->always, fn (MaskPath $always) => $always->leads($path));
    }

    /**
     * Whether a "Never mask" path turns off the name matched at $matchedAt
     * for the value at $path: it names the matched key, the value, or a
     * place between them.
     *
     * @param  list<string|int>  $matchedAt
     * @param  list<string|int>  $path
     */
    public function cancels(array $matchedAt, array $path): bool
    {
        return array_any($this->never, fn (MaskPath $never) => count($never->segments) >= count($matchedAt) && $never->leads($path));
    }

    /**
     * @return list<MaskPath>
     */
    protected static function parsed(mixed $paths): array
    {
        $parsed = [];

        foreach (is_array($paths) ? $paths : [] as $path) {
            try {
                $parsed[] = MaskPath::parse((string) $path);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $parsed;
    }
}
