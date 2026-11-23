<?php

namespace App\Engine\Definitions;

/**
 * An output a node produces. The data picker lists these, with their
 * children, as the values later nodes can select.
 */
final readonly class Output
{
    /**
     * @param  'string'|'number'|'boolean'|'object'|'array'|'file'|'any'  $type
     * @param  list<Output>  $children  properties of an object, or of each item of an array
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'any',
        public ?string $description = null,
        public array $children = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'description' => $this->description,
            'children' => array_map(fn (Output $child) => $child->toArray(), $this->children),
        ];
    }
}
