<?php

namespace App\Engine\Graph;

use App\Enums\ValueType;

/**
 * A workflow variable as the graph declares it: a typed value that lasts
 * for one run. Its starting value is a template worked out when the run
 * starts, which may read the trigger, the data store, and the run itself.
 * The type is kept as saved, so one this install doesn't know can be
 * reported instead of lost.
 */
final readonly class GraphVariable
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public string $value = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: is_scalar($data['name'] ?? null) ? (string) $data['name'] : '',
            type: is_scalar($data['type'] ?? null) ? (string) $data['type'] : '',
            value: is_scalar($data['value'] ?? null) ? (string) $data['value'] : '',
        );
    }

    /**
     * @return array{name: string, type: string, value: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'value' => $this->value];
    }

    public function valueType(): ?ValueType
    {
        return ValueType::tryFrom($this->type);
    }
}
