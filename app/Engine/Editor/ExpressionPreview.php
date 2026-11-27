<?php

namespace App\Engine\Editor;

use App\Engine\ContextBuilder;
use App\Engine\Graph\GraphVariable;
use App\Engine\Graph\VariableValidator;
use App\Engine\LoopResults;
use App\Engine\Redactor;
use App\Engine\RunVariables;
use App\Engine\StoreData;
use App\Engine\Templates\TemplateEngine;
use App\Engine\Templates\TemplateError;
use App\Engine\VariableData;
use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Workflow;
use App\Services\DataStore;
use App\Services\WorkflowFiles;
use RuntimeException;

/**
 * Works out what a templated field of a node would become, for the editor
 * to show as the person types. It uses the data of the workflow's latest
 * run that reached the node: what the node itself saw, when it ran, or
 * otherwise what that run's trigger and steps produced. With no runs yet,
 * references read as empty. Workflow variables read as that run left
 * them, and the data store as it is now. Secrets read as masked, so their
 * values never reach the editor.
 *
 * It also works out what a workflow variable would start a run as, from
 * the latest run's trigger input and the data store as it is now.
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
        protected DataStore $store,
        protected RunVariables $variables,
        protected VariableValidator $validator,
    ) {}

    /**
     * @return array{status: 'value'|'empty'|'error', value: string, type: string, source: string}
     */
    public function preview(Workflow $workflow, string $nodeId, string $template): array
    {
        [$data, $execution] = $this->sampleData($workflow, $nodeId);
        $source = $this->sourceOf($execution);

        $this->files->scopeTo($workflow->workspace);

        try {
            $value = Redactor::forWorkspace($workflow->workspace)->mask($this->templates->render($template, $data));
        } catch (TemplateError|RuntimeException $e) {
            return ['status' => 'error', 'value' => $e->getMessage(), 'type' => '', 'source' => $source];
        }

        return $this->shown($value, $source);
    }

    /**
     * @return array{status: 'value'|'empty', value: string, type: string, source: string}
     */
    protected function shown(mixed $value, string $source): array
    {
        if ($value === null || $value === '' || $value === []) {
            return ['status' => 'empty', 'value' => '', 'type' => $this->typeOf($value), 'source' => $source];
        }

        return ['status' => 'value', 'value' => $this->display($value), 'type' => $this->typeOf($value), 'source' => $source];
    }

    /**
     * Which run the previewed data comes from, in words.
     */
    protected function sourceOf(?Execution $execution): string
    {
        return $execution
            ? __('workflows.editor.preview_from_run', ['run' => Execution::reference($execution->id), 'when' => $execution->created_at?->diffForHumans()])
            : __('workflows.editor.preview_no_runs_yet');
    }

    /**
     * What {{ execution }} reads before the workflow has run.
     *
     * @return array{id: null, workflow: string, trigger_type: null, trigger: null, started_at: null}
     */
    protected function aboutNoRun(Workflow $workflow): array
    {
        return ['id' => null, 'workflow' => $workflow->name, 'trigger_type' => null, 'trigger' => null, 'started_at' => null];
    }

    /**
     * What the variable would start a run as: its starting value worked
     * out as a run does it, converted to the variable's type. Anything
     * that would stop a run from starting previews as the error.
     *
     * @return array{status: 'value'|'empty'|'error', value: string, type: string, source: string}
     */
    public function previewStartingValue(Workflow $workflow, GraphVariable $variable): array
    {
        $execution = $workflow->executions()->latest()->latest('id')->first();
        $source = $this->sourceOf($execution);

        if ($problems = $this->validator->startingValueProblems($variable)) {
            return ['status' => 'error', 'value' => $problems[0], 'type' => '', 'source' => $source];
        }

        $data = $execution ? $this->contexts->atStart($execution) : [
            'trigger' => [],
            'store' => new StoreData($workflow->workspace, $this->store),
            'execution' => $this->aboutNoRun($workflow),
        ];

        $this->files->scopeTo($workflow->workspace);

        try {
            $value = $this->variables->startingValue($variable, $data, Redactor::forWorkspace($workflow->workspace));
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'value' => $e->getMessage(), 'type' => '', 'source' => $source];
        }

        return $this->shown($value, $source);
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
            'vars' => new VariableData($execution),
            'store' => new StoreData($workflow->workspace, $this->store),
            'execution' => [
                'id' => $execution?->id,
                'workflow' => $workflow->name,
                'trigger_type' => $execution?->trigger_type,
                'trigger' => $execution?->triggerDetails(),
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
            ->get(['id', 'node_id', 'scope_key', 'status', 'wait_type', 'output', 'output_ports']);

        $results = new LoopResults($completed);

        foreach ($completed as $done) {
            $outputs[$done->node_id] ??= $results->entry($done);
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
