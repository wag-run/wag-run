<?php

namespace App\Engine;

use App\Engine\Definitions\Field;
use App\Engine\Graph\GraphNode;
use App\Engine\Templates\DataBag;
use App\Engine\Templates\TemplateEngine;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\WorkflowFile;
use App\Models\Workspace;
use App\Services\WorkflowFiles;
use Illuminate\Support\Str;
use RuntimeException;

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
        app(WorkflowFiles::class)->scopeTo($this->workspace());

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

    /**
     * A data store entry's value as it is now, one item of a collection,
     * or, with no item key, a whole collection. Null when there is none.
     */
    public function store(string $key, ?string $itemKey = null): mixed
    {
        $value = $this->data['store'][$key] ?? null;

        return DataBag::unwrap($itemKey !== null && $value instanceof StoreData ? $value[$itemKey] : $value);
    }

    public function secret(string $key): ?string
    {
        return $this->data['secrets'][$key] ?? null;
    }

    public function credential(?string $id): ?Credential
    {
        if (blank($id) || ! Str::isUuid($id)) {
            return null;
        }

        return $this->workspace()->credentials()->whereKey($id)->first();
    }

    /**
     * Render a template against the run's data, with $extra on top, such as
     * the item a Filter is checking.
     *
     * @param  array<string, mixed>  $extra
     */
    public function render(string $template, array $extra = []): mixed
    {
        app(WorkflowFiles::class)->scopeTo($this->workspace());

        return $this->templates->render($template, [...$this->data, ...$extra]);
    }

    /**
     * The file a reference from an input points to, if it is one of this
     * workspace's files.
     */
    public function file(mixed $reference): ?WorkflowFile
    {
        return app(WorkflowFiles::class)->find($this->workspace(), $reference);
    }

    /**
     * Every file an input holds: one file or a list of them. Anything that
     * is not a file of this workspace is reported by name.
     *
     * @return list<WorkflowFile>
     *
     * @throws RuntimeException when a value is not a file of this workspace
     */
    public function files(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $items = WorkflowFiles::isReference($value) || ! is_array($value) || ! array_is_list($value) ? [$value] : $value;

        return array_map(fn (mixed $item) => $this->file($item) ?? throw new RuntimeException(
            WorkflowFiles::isReference($item) ? __('run_files.file_not_found') : __('run_files.not_a_file'),
        ), $items);
    }

    /**
     * Save bytes as a file of this run.
     *
     * @return array{kind: 'file', id: string, name: string, mime: string, size: int}
     */
    public function storeFile(string $contents, string $name, ?string $mime = null): array
    {
        return app(WorkflowFiles::class)->store($this->workspace(), $this->execution, $contents, $name, $mime);
    }

    /**
     * Save a local file, such as a download, as a file of this run.
     *
     * @return array{kind: 'file', id: string, name: string, mime: string, size: int}
     */
    public function storeFilePath(string $path, string $name, ?string $mime = null): array
    {
        return app(WorkflowFiles::class)->storePath($this->workspace(), $this->execution, $path, $name, $mime);
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
