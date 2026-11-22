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
        return __('Form submitted');
    }

    public function description(): string
    {
        return __('A form is submitted.');
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
            new Field('title', __('Form title'), Field::TEXT, required: true),
            new Field('description', __('Description'), Field::TEXTAREA),
            new Field('fields', __('Fields'), Field::LIST, required: true, fields: Task::formFieldDefinition()),
            new Field('access', __('Who can submit'), Field::SELECT, required: true, default: 'public', options: [
                'public' => __('Anyone with the link'),
                'members' => __('Signed-in members of this workspace'),
            ]),
            new Field('success_message', __('Message after submitting'), Field::TEXT, default: 'Thanks, your response was submitted.'),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('values', __('Submitted values'), 'object', children: Task::outputsForFields($config['fields'] ?? [])),
            new Output('submitted_at', __('Submitted at'), 'string'),
            new Output('submitted_by', __('Submitted by (user id)'), 'number'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }
}
