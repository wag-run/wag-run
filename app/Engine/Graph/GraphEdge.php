<?php

namespace App\Engine\Graph;

final readonly class GraphEdge
{
    public function __construct(
        public string $id,
        public string $source,
        public string $target,
        public string $sourcePort = 'main',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            source: (string) ($data['source'] ?? ''),
            target: (string) ($data['target'] ?? ''),
            sourcePort: (string) ($data['source_port'] ?? 'main'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'source_port' => $this->sourcePort,
            'target' => $this->target,
        ];
    }
}
