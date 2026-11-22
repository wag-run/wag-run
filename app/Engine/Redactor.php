<?php

namespace App\Engine;

use App\Models\Credential;
use App\Models\Workspace;

/**
 * Masks a workspace's secret and authentication values before anything is
 * written to step rows, so execution logs never hold them.
 */
class Redactor
{
    public const MASK = '********';

    /**
     * Values shorter than this are not masked, so a secret like "1" does not
     * blank out every digit in a log.
     */
    public const MIN_LENGTH = 4;

    /**
     * @param  list<string>  $values  longest first
     */
    public function __construct(protected array $values = []) {}

    public static function forWorkspace(Workspace $workspace): self
    {
        $values = $workspace->secrets()->get()->pluck('value')->all();

        foreach ($workspace->credentials()->get() as $credential) {
            /** @var Credential $credential */
            array_push($values, ...$credential->secretValues());
        }

        $values = array_values(array_unique(array_filter(
            array_map('strval', $values),
            fn (string $value) => mb_strlen($value) >= self::MIN_LENGTH,
        )));

        usort($values, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return new self($values);
    }

    public function mask(mixed $value): mixed
    {
        if ($this->values === []) {
            return $value;
        }

        if (is_string($value)) {
            return str_replace($this->values, self::MASK, $value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->mask($item), $value);
        }

        return $value;
    }
}
