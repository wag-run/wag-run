<?php

namespace App\Engine\Editor;

use App\Engine\Graph\WorkflowGraph;
use App\Enums\ConcurrencyPolicy;
use App\Models\Workflow;
use Illuminate\Validation\ValidationException;

/**
 * The JSON format workflows import and export in. It carries the draft graph
 * and settings, never secret or authentication values: nodes reference those
 * by key, so the importing workspace must define them.
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
            throw ValidationException::withMessages(['file' => __('This is not a workflow export file.')]);
        }

        if ((int) ($data['version'] ?? 0) > self::VERSION) {
            throw ValidationException::withMessages(['file' => __('This file was exported by a newer version of the app.')]);
        }

        if (! is_array($data['graph'] ?? null) || ! is_array($data['graph']['nodes'] ?? null) || ! is_array($data['graph']['edges'] ?? [])) {
            throw ValidationException::withMessages(['file' => __('The file has no workflow graph.')]);
        }

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 255) ?: __('Imported workflow'),
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'concurrency' => ConcurrencyPolicy::tryFrom((string) ($data['concurrency'] ?? '')) ?? ConcurrencyPolicy::Allow,
            'retention_days' => is_numeric($data['retention_days'] ?? null) ? max(1, (int) $data['retention_days']) : null,
            'graph' => WorkflowGraph::fromArray($data['graph']),
        ];
    }
}
