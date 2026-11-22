<?php

namespace App\Engine\Nodes\Triggers;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Nodes\Actions\Task;
use App\Enums\NodeKind;

/**
 * Starts the workflow when its form is submitted. Form pages are public by
 * default; they can be limited to signed-in workspace members.
 */
class FormTrigger extends Node
{
    public static function type(): string
    {
        return 'core.form_trigger';
    }

    public function label(): string
    {
        return __('nodes.form_trigger.form_submitted');
    }

    public function description(): string
    {
        return __('nodes.form_trigger.form_is_submitted');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'document-text';
    }

    public function inputs(): array
    {
        return [
            new Field('title', __('nodes.form_trigger.form_title'), Field::TEXT, required: true),
            new Field('description', __('common.description'), Field::TEXTAREA),
            new Field('fields', __('nodes.form_trigger.fields'), Field::LIST, required: true, fields: Task::formFieldDefinition()),
            new Field('access', __('nodes.form_trigger.who_can_submit'), Field::SELECT, required: true, default: 'public', options: [
                'public' => __('nodes.form_trigger.anyone_with_the_link'),
                'members' => __('nodes.form_trigger.signed_in_members_of_this'),
            ]),
            new Field('success_message', __('nodes.form_trigger.message_after_submitting'), Field::TEXT, default: 'Thanks, your response was submitted.'),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('values', __('common.submitted_values'), 'object', children: Task::outputsForFields($config['fields'] ?? [])),
            new Output('submitted_at', __('nodes.form_trigger.submitted_at'), 'string'),
            new Output('submitted_by', __('common.submitted_by_user_id'), 'string'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }
}
