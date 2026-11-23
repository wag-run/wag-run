<?php

namespace App\Engine;

use App\Engine\Definitions\Field;
use App\Engine\Graph\GraphNode;
use App\Engine\Templates\TemplateEngine;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Workspace;

/**
 * Everything a node can use while it runs.
 */
class NodeContext
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $resolved = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly ExecutionStep $step,
        public readonly Execution $execution,
        public readonly GraphNode $node,
        protected Node $definition,
        protected array $data,
        protected TemplateEngine $templates,
    ) {}

    /**
     * A raw config value, exactly as saved in the graph.
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->node->config : ($this->node->config[$key] ?? $default);
    }

    /**
     * An input with templates rendered and expressions evaluated.
     */
    public function input(string $name, mixed $default = null): mixed
    {
        return $this->inputs()[$name] ?? $default;
    }

    /**
     * Every declared input, resolved. This is what the step row records as
     * its input (after masking).
     *
     * @return array<string, mixed>
     */
    public function inputs(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->definition->inputs() as $field) {
            $resolved[$field->name] = $this->resolve($field, $this->node->config[$field->name] ?? $field->default);
        }

        return $this->resolved = $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * State the step saved when it started waiting.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->step->state ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function triggerInput(): array
    {
        return $this->execution->input ?? [];
    }

    public function workspace(): Workspace
    {
        return $this->execution->workspace;
    }

    public function variable(string $key): ?string
    {
        return $this->data['vars'][$key] ?? null;
    }

    public function secret(string $key): ?string
    {
        return $this->data['secrets'][$key] ?? null;
    }

    public function credential(?string $key): ?Credential
    {
        if (blank($key)) {
            return null;
        }

        return $this->workspace()->credentials()->where('key', $key)->first();
    }

    public function render(string $template): mixed
    {
        return $this->templates->render($template, $this->data);
    }

    /**
     * Tell the sweep this long-running step's worker is still alive.
     */
    public function heartbeat(): void
    {
        $this->step->newQuery()->whereKey($this->step->id)->update(['heartbeat_at' => now()]);
    }

    protected function resolve(Field $field, mixed $value): mixed
    {
        if ($field->templated) {
            return $this->renderDeep($value);
        }

        return $value;
    }

    protected function renderDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->render($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->renderDeep($item), $value);
        }

        return $value;
    }
}
