<?php

namespace App\Services;

use App\Engine\Engine;
use App\Engine\Graph\GraphNode;
use App\Engine\Nodes\Triggers\ManualTrigger;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Runs a workflow from inside the app. Every published workflow can be run
 * this way: from its Manual trigger when it has one, asking for the inputs
 * that trigger declares, else from its first trigger with an empty payload.
 */
class ManualRuns
{
    public function __construct(protected Engine $engine) {}

    /**
     * The Manual trigger of the published version, if it has one.
     */
    public function manualTrigger(Workflow $workflow): ?GraphNode
    {
        foreach ($workflow->publishedVersion?->toGraph()->nodes ?? [] as $node) {
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
    public function fields(Workflow $workflow): array
    {
        $fields = (array) ($this->manualTrigger($workflow)?->config['fields'] ?? []);

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
        $input = [];

        if ($trigger) {
            $attributes = [];

            foreach ($this->fields($workflow) as $field) {
                $attributes[$field['name']] = $field['label'] ?? $field['name'];
            }

            $input = Assignments::castValues($this->fields($workflow), Validator::make($values, ManualTrigger::rules($trigger->config), [], $attributes)->validate());
        }

        return $this->engine->start($workflow, $input, $user, 'manual', $trigger?->id);
    }
}
