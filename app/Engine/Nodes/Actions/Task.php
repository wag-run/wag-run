<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Resumption;
use App\Enums\AssignmentType;
use App\Enums\NodeKind;
use App\Enums\ResumeReason;
use App\Services\Assignments;

/**
 * Assigns a manual task with a form to fill in before the flow continues.
 * The submitted values become the node's output.
 */
class Task extends Node
{
    public function __construct(protected Assignments $assignments) {}

    /**
     * The kinds of form field a Task, Form trigger or Manual trigger can ask for.
     *
     * @return array<string, string>
     */
    public static function fieldTypes(): array
    {
        return [
            'text' => __('nodes.task.field_types.text'),
            'textarea' => __('nodes.task.field_types.textarea'),
            'number' => __('nodes.task.field_types.number'),
            'email' => __('nodes.task.field_types.email'),
            'date' => __('nodes.task.field_types.date'),
            'checkbox' => __('nodes.task.field_types.checkbox'),
            'select' => __('nodes.task.field_types.select'),
        ];
    }

    public static function type(): string
    {
        return 'core.task';
    }

    public function label(): string
    {
        return __('common.task');
    }

    public function description(): string
    {
        return __('nodes.task.assigns_a_manual_task_with');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('common.people');
    }

    public function icon(): string
    {
        return 'clipboard-document-check';
    }

    public function inputs(): array
    {
        return [
            new Field('assignee', __('nodes.task.assignee'), Field::USER, required: true),
            new Field('title', __('common.title'), Field::TEXT, required: true, templated: true),
            new Field('instructions', __('common.instructions'), Field::TEXTAREA, templated: true),
            new Field('fields', __('common.form_fields'), Field::LIST, fields: static::formFieldDefinition()),
            new Field('timeout_hours', __('common.timeout_hours'), Field::NUMBER, default: 48),
            new Field('email', __('nodes.task.also_email_the_assignee'), Field::BOOLEAN, default: true, description: __('nodes.task.they_always_get_an_in_app')),
        ];
    }

    /**
     * The definition of one form field, shared with the Form and Manual triggers.
     *
     * @return list<Field>
     */
    public static function formFieldDefinition(): array
    {
        return [
            new Field('name', __('common.key'), Field::TEXT, required: true),
            new Field('label', __('nodes.task.label'), Field::TEXT, required: true),
            new Field('type', __('common.type'), Field::SELECT, required: true, default: 'text', options: static::fieldTypes()),
            new Field('required', __('common.required'), Field::BOOLEAN, default: false),
            new Field('options', __('nodes.task.choices'), Field::KEY_VALUE, description: __('nodes.task.for_choice_fields_value_and'), visibleWhen: ['type' => 'select']),
        ];
    }

    /**
     * The outputs a list of form fields produces, for the data picker.
     *
     * @return list<Output>
     */
    public static function outputsForFields(mixed $fields): array
    {
        return array_values(array_map(
            fn (array $field) => new Output((string) $field['name'], (string) ($field['label'] ?? $field['name']), match ($field['type'] ?? 'text') {
                'number' => 'number',
                'checkbox' => 'boolean',
                default => 'string',
            }),
            array_filter(is_array($fields) ? $fields : [], fn ($field) => is_array($field) && filled($field['name'] ?? null)),
        ));
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('values', __('common.submitted_values'), 'object', children: static::outputsForFields($config['fields'] ?? [])),
            new Output('responded_by', __('common.submitted_by_user_id'), 'string'),
            new Output('responded_by_name', __('nodes.task.submitted_by'), 'string'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $assignee = $this->assignments->activeUser($context->input('assignee'));

        if (! $assignee) {
            return NodeResult::fail(__('nodes.task.assignee_does_not_have_an'), retryable: false);
        }

        $dueAt = now()->addHours(max(1, (int) $context->input('timeout_hours', 48)));
        $instructions = $context->input('instructions');

        $assignment = $this->assignments->create(
            $context, AssignmentType::Task, $assignee,
            (string) $context->input('title'),
            $instructions === null || $instructions === '' ? null : (string) $instructions,
            $dueAt,
            array_values(array_filter((array) $context->input('fields', []), fn ($field) => is_array($field) && filled($field['name'] ?? null))),
            email: (bool) $context->input('email'),
        );

        return NodeResult::waitForResponse($dueAt, ['assignment_id' => $assignment->id]);
    }

    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        if ($resumption->reason === ResumeReason::TimerElapsed) {
            $this->assignments->expireFromState($context->state());

            return NodeResult::fail(__('nodes.task.task_was_not_completed_before'), retryable: false);
        }

        return NodeResult::complete([
            'values' => $resumption->payload['values'] ?? [],
            'responded_by' => $resumption->payload['responded_by'] ?? null,
            'responded_by_name' => $resumption->payload['responded_by_name'] ?? null,
        ]);
    }
}
