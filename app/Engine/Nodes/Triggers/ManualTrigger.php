<?php

namespace App\Engine\Nodes\Triggers;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Nodes\Actions\Task;
use App\Enums\NodeKind;
use App\Services\Assignments;

/**
 * Starts the workflow when a user runs it from inside the app. Any inputs it
 * declares are asked for before the run starts, and arrive under "values",
 * as a form's answers do.
 */
class ManualTrigger extends Node
{
    public static function type(): string
    {
        return 'core.manual_trigger';
    }

    public function label(): string
    {
        return __('nodes.manual_trigger.manual');
    }

    public function description(): string
    {
        return __('nodes.manual_trigger.user_runs_the_workflow_from');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'cursor-arrow-rays';
    }

    public function inputs(): array
    {
        return [new Field('fields', __('nodes.manual_trigger.inputs_to_ask_for'), Field::LIST, fields: Task::formFieldDefinition())];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('values', __('nodes.manual_trigger.input_values'), 'object', children: Task::outputsForFields($config['fields'] ?? []))];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }

    /**
     * The required inputs that $input has no value for under "values". Runs
     * started from the app or the API are validated against rules() first;
     * this catches runs another workflow starts.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function missingInputs(array $config, array $input): array
    {
        return Task::missingRequiredFields($config['fields'] ?? [], (array) ($input['values'] ?? []));
    }

    /**
     * Validation rules for the inputs this trigger asks for.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, list<string>>
     */
    public static function rules(array $config): array
    {
        $rules = [];

        foreach ((array) ($config['fields'] ?? []) as $field) {
            if (is_array($field) && filled($field['name'] ?? null)) {
                $rules[$field['name']] = Assignments::rulesFor($field);
            }
        }

        return $rules;
    }
}
