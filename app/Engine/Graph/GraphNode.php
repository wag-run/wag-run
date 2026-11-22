<?php

namespace App\Engine\Graph;

final readonly class GraphNode
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $retry
     * @param  array{x: float|int, y: float|int}  $position
     * @param  bool  $errorPath  whether the node shows its "on error" output in the editor
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $name,
        public array $config = [],
        public ?array $retry = null,
        public array $position = ['x' => 0, 'y' => 0],
        public bool $errorPath = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            name: (string) ($data['name'] ?? ($data['id'] ?? '')),
            config: is_array($data['config'] ?? null) ? $data['config'] : [],
            retry: is_array($data['retry'] ?? null) ? $data['retry'] : null,
            position: [
                'x' => (float) ($data['position']['x'] ?? 0),
                'y' => (float) ($data['position']['y'] ?? 0),
            ],
            errorPath: (bool) ($data['error_path'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'config' => $this->config,
            'retry' => $this->retry,
            'position' => $this->position,
            'error_path' => $this->errorPath ?: null,
        ], fn ($value) => $value !== null);
    }

    public function withErrorPath(bool $errorPath): self
    {
        return new self($this->id, $this->type, $this->name, $this->config, $this->retry, $this->position, $errorPath);
    }
}
