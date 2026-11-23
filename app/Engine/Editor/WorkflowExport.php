<?php

namespace App\Engine\Editor;

use App\Engine\Definitions\Field;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\ConcurrencyPolicy;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * The JSON format workflows import and export in. It carries the draft graph
 * and settings, never secret or authentication values. Nodes reference
 * secrets by key, so the importing workspace must define them, and
 * authentication settings by their UUID, which only matches in the workspace
 * the workflow came from: elsewhere the field is left empty to choose again.
 */
class WorkflowExport
{
    public const FORMAT = 'wag.run/workflow';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function export(Workflow $workflow): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'concurrency' => $workflow->concurrency->value,
            'retention_days' => $workflow->retention_days,
            'graph' => $workflow->draft()->toArray(),
        ];
    }

    /**
     * $graph with every authentication setting field that names a setting
     * this workspace doesn't have left empty.
     */
    public static function withOwnCredentials(WorkflowGraph $graph, Workspace $workspace): WorkflowGraph
    {
        $registry = app(NodeRegistry::class);
        $own = $workspace->credentials()->pluck('id')->all();

        foreach ($graph->nodes as $node) {
            if (! $registry->has($node->type)) {
                continue;
            }

            $config = $node->config;

            foreach ($registry->make($node->type)->inputs() as $field) {
                if ($field->type === Field::CREDENTIAL && filled($config[$field->name] ?? null) && ! in_array($config[$field->name], $own, true)) {
                    unset($config[$field->name]);
                }
            }

            if ($config !== $node->config) {
                $graph = $graph->withNode(new GraphNode($node->id, $node->type, $node->name, $config, $node->retry, $node->position, $node->errorPath));
            }
        }

        return $graph;
    }

    /**
     * Parse an exported file.
     *
     * @return array{name: string, description: string|null, concurrency: ConcurrencyPolicy, retention_days: int|null, graph: WorkflowGraph}
     *
     * @throws ValidationException
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw ValidationException::withMessages(['file' => __('workflows.editor.this_is_not_a_workflow')]);
        }

        if ((int) ($data['version'] ?? 0) > self::VERSION) {
            throw ValidationException::withMessages(['file' => __('workflows.editor.this_file_was_exported_by')]);
        }

        if (! is_array($data['graph'] ?? null) || ! is_array($data['graph']['nodes'] ?? null) || ! is_array($data['graph']['edges'] ?? [])) {
            throw ValidationException::withMessages(['file' => __('workflows.editor.file_has_no_workflow_graph')]);
        }

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 255) ?: __('workflows.editor.imported_workflow'),
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'concurrency' => ConcurrencyPolicy::tryFrom((string) ($data['concurrency'] ?? '')) ?? ConcurrencyPolicy::Allow,
            'retention_days' => is_numeric($data['retention_days'] ?? null) ? max(1, (int) $data['retention_days']) : null,
            'graph' => WorkflowGraph::fromArray($data['graph']),
        ];
    }
}
