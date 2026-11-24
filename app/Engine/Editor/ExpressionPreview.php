<?php

namespace App\Engine\Editor;

use App\Engine\ContextBuilder;
use App\Engine\Redactor;
use App\Engine\Templates\TemplateEngine;
use App\Engine\Templates\TemplateError;
use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Workflow;
use App\Services\WorkflowFiles;
use RuntimeException;

/**
 * Works out what a templated field of a node would become, for the editor
 * to show as the person types. It uses the data of the workflow's latest
 * run that reached the node: what the node itself saw, when it ran, or
 * otherwise what that run's trigger and steps produced. With no runs yet,
 * references read as empty. Secrets read as masked, so their values never
 * reach the editor.
 */
class ExpressionPreview
{
    /**
     * Longer results are cut, so a large response body stays readable.
     */
    public const MAX_LENGTH = 2000;

    public function __construct(
        protected TemplateEngine $templates,
        protected ContextBuilder $contexts,
        protected WorkflowFiles $files,
    ) {}

    /**
     * @return array{status: 'value'|'empty'|'error', value: string, type: string, source: string}
     */
    public function preview(Workflow $workflow, string $nodeId, string $template): array
    {
        [$data, $execution] = $this->sampleData($workflow, $nodeId);
        $source = $execution
            ? __('workflows.editor.preview_from_run', ['run' => Execution::reference($execution->id), 'when' => $execution->created_at?->diffForHumans()])
            : __('workflows.editor.preview_no_runs_yet');

        $this->files->scopeTo($workflow->workspace);

        try {
            $value = Redactor::forWorkspace($workflow->workspace)->mask($this->templates->render($template, $data));
        } catch (TemplateError|RuntimeException $e) {
            return ['status' => 'error', 'value' => $e->getMessage(), 'type' => '', 'source' => $source];
        }

        if ($value === null || $value === '' || $value === []) {
            return ['status' => 'empty', 'value' => '', 'type' => $this->typeOf($value), 'source' => $source];
        }

        return ['status' => 'value', 'value' => $this->display($value), 'type' => $this->typeOf($value), 'source' => $source];
    }

    /**
     * The data a step of the node can read, and the run it comes from.
     *
     * @return array{array<string, mixed>, Execution|null}
     */
    protected function sampleData(Workflow $workflow, string $nodeId): array
    {
        $step = ExecutionStep::query()
            ->whereIn('execution_id', $workflow->executions()->select('id'))
            ->where('node_id', $nodeId)
            ->latest('id')
            ->first();

        if ($step) {
            return [$this->withMaskedSecrets($workflow, $this->contexts->build($step)), $step->execution];
        }

        $execution = $workflow->executions()->latest()->latest('id')->first();

        return [$this->withMaskedSecrets($workflow, [
            'trigger' => $execution->input ?? [],
            'nodes' => $execution ? $this->outputsOf($execution) : [],
            'vars' => $workflow->workspace->variables()->pluck('value', 'key')->all(),
            'execution' => [
                'id' => $execution?->id,
                'workflow' => $workflow->name,
                'trigger_type' => $execution?->trigger_type,
                'started_at' => $execution?->started_at?->toIso8601String(),
            ],
        ]), $execution];
    }

    /**
     * Each node's output in a run, from its first completed step: the top
     * level, or a loop's first item.
     *
     * @return array<string, array{output: mixed, port: string|null}>
     */
    protected function outputsOf(Execution $execution): array
    {
        $outputs = [];

        $completed = $execution->steps()
            ->where('status', StepStatus::Complete)
            ->orderBy('id')
            ->get(['id', 'node_id', 'output', 'output_ports']);

        foreach ($completed as $done) {
            $outputs[$done->node_id] ??= ['output' => $done->output, 'port' => $done->output_ports[0] ?? null];
        }

        return $outputs;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withMaskedSecrets(Workflow $workflow, array $data): array
    {
        $data['secrets'] = array_fill_keys($workflow->workspace->secrets()->pluck('key')->all(), Redactor::MASK);

        return $data;
    }

    protected function display(mixed $value): string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH).'…' : $text;
    }

    protected function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => __('workflows.editor.preview_types.empty'),
            is_bool($value) => __('workflows.editor.preview_types.boolean'),
            is_int($value), is_float($value) => __('workflows.editor.preview_types.number'),
            is_string($value) => __('workflows.editor.preview_types.text'),
            WorkflowFiles::isReference($value) => __('workflows.editor.preview_types.file'),
            is_array($value) && array_is_list($value) => __('workflows.editor.preview_types.list'),
            default => __('workflows.editor.preview_types.object'),
        };
    }
}
