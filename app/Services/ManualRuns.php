<?php

namespace App\Services;

use App\Engine\Engine;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Triggers\ManualTrigger;
use App\Enums\NodeKind;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Runs a workflow from inside the app. Every published workflow can be run
 * this way: from its Manual trigger when it has one, asking for the inputs
 * that trigger declares, else from its first trigger with an empty payload.
 *
 * The draft can be test-run the same way without publishing it. Its run is
 * pinned to a snapshot of the draft; without a Manual trigger, the first
 * trigger receives the sample data the person gives as JSON.
 */
class ManualRuns
{
    public function __construct(
        protected Engine $engine,
        protected Workflows $workflows,
        protected NodeRegistry $registry,
    ) {}

    /**
     * The Manual trigger of the published version, or of $graph, if it has one.
     */
    public function manualTrigger(Workflow $workflow, ?WorkflowGraph $graph = null): ?GraphNode
    {
        $graph ??= $workflow->publishedVersion?->toGraph();

        foreach ($graph->nodes ?? [] as $node) {
            if ($node->type === ManualTrigger::type()) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The inputs to ask for before the run starts.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(Workflow $workflow, ?WorkflowGraph $graph = null): array
    {
        $fields = (array) ($this->manualTrigger($workflow, $graph)?->config['fields'] ?? []);

        return array_values(array_filter($fields, fn (mixed $field) => is_array($field) && filled($field['name'] ?? null)));
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException when the values do not match the trigger's inputs
     */
    public function start(Workflow $workflow, User $user, array $values = []): Execution
    {
        if (! $workflow->publishedVersion) {
            throw ValidationException::withMessages(['run' => __('workflows.publish_the_workflow_before_running')]);
        }

        $trigger = $this->manualTrigger($workflow);
        $input = $trigger ? $this->validatedInput($workflow, $trigger, $values) : [];

        return $this->engine->start($workflow, $input, $user, 'manual', $trigger?->id);
    }

    /**
     * The trigger a test run of the draft starts at: its Manual trigger when
     * it has one, else its first trigger.
     */
    public function testTrigger(Workflow $workflow): ?GraphNode
    {
        $graph = $workflow->draft();

        return $this->manualTrigger($workflow, $graph) ?? collect($graph->nodes)->first(
            fn (GraphNode $node) => $this->registry->has($node->type) && $this->registry->make($node->type)->kind() === NodeKind::Trigger,
        );
    }

    /**
     * Test-run the draft without publishing it.
     *
     * @param  array<string, mixed>  $values  the Manual trigger's inputs
     * @param  string|null  $payload  JSON the first trigger receives when there is no Manual trigger
     *
     * @throws ValidationException when the draft cannot run ("graph"), the inputs are invalid, or the payload is not a JSON object ("payload")
     */
    public function startTest(Workflow $workflow, User $user, array $values = [], ?string $payload = null): Execution
    {
        $version = $this->workflows->draftSnapshot($workflow, $user);
        $graph = $version->toGraph();
        $trigger = $this->manualTrigger($workflow, $graph);

        if ($trigger) {
            $input = $this->validatedInput($workflow, $trigger, $values, $graph);
        } else {
            $input = trim((string) $payload) === '' ? [] : json_decode((string) $payload, true);

            if (! is_array($input) || ($input !== [] && array_is_list($input))) {
                throw ValidationException::withMessages(['payload' => __('workflows.edit.sample_data_must_be_a_json_object')]);
            }
        }

        return $this->engine->start($workflow, $input, $user, 'manual', $trigger?->id, version: $version);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function validatedInput(Workflow $workflow, GraphNode $trigger, array $values, ?WorkflowGraph $graph = null): array
    {
        $fields = $this->fields($workflow, $graph);
        $attributes = [];

        foreach ($fields as $field) {
            $attributes[$field['name']] = $field['label'] ?? $field['name'];
        }

        return Assignments::castValues($fields, Validator::make($values, ManualTrigger::rules($trigger->config), [], $attributes)->validate(), $workflow->workspace);
    }
}
