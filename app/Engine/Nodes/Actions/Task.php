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
    public const FIELD_TYPES = ['text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'email' => 'Email', 'date' => 'Date', 'checkbox' => 'Checkbox', 'select' => 'Choice'];

    public function __construct(protected Assignments $assignments) {}

    public static function type(): string
    {
        return 'core.task';
    }

    public function label(): string
    {
        return __('Task');
    }

    public function description(): string
    {
        return __('Assigns a manual task with a form to fill in before the flow continues.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('People');
    }

    public function icon(): string
    {
        return 'clipboard-document-check';
    }

    public function inputs(): array
    {
        return [
            new Field('assignee', __('Assignee'), Field::USER, required: true),
            new Field('title', __('Title'), Field::TEXT, required: true, templated: true),
            new Field('instructions', __('Instructions'), Field::TEXTAREA, templated: true),
            new Field('fields', __('Form fields'), Field::LIST, fields: static::formFieldDefinition()),
            new Field('timeout_hours', __('Timeout (hours)'), Field::NUMBER, default: 48),
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
            new Field('name', __('Key'), Field::TEXT, required: true),
            new Field('label', __('Label'), Field::TEXT, required: true),
            new Field('type', __('Type'), Field::SELECT, required: true, default: 'text', options: self::FIELD_TYPES),
            new Field('required', __('Required'), Field::BOOLEAN, default: false),
            new Field('options', __('Choices'), Field::KEY_VALUE, description: __('For choice fields: value and label.')),
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
            new Output('values', __('Submitted values'), 'object', children: static::outputsForFields($config['fields'] ?? [])),
            new Output('responded_by', __('Submitted by (user id)'), 'number'),
            new Output('responded_by_name', __('Submitted by'), 'string'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $assignee = $this->assignments->activeUser($context->input('assignee'));

        if (! $assignee) {
            return NodeResult::fail(__('The assignee does not have an active account on this install.'), retryable: false);
        }

        $dueAt = now()->addHours(max(1, (int) $context->input('timeout_hours', 48)));
        $instructions = $context->input('instructions');

        $assignment = $this->assignments->create(
            $context, AssignmentType::Task, $assignee,
            (string) $context->input('title'),
            $instructions === null || $instructions === '' ? null : (string) $instructions,
            $dueAt,
            array_values(array_filter((array) $context->input('fields', []), fn ($field) => is_array($field) && filled($field['name'] ?? null))),
        );

        return NodeResult::waitForResponse($dueAt, ['assignment_id' => $assignment->id]);
    }

    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        if ($resumption->reason === ResumeReason::TimerElapsed) {
            $this->assignments->expireFromState($context->state());

            return NodeResult::fail(__('The task was not completed before it timed out.'), retryable: false);
        }

        return NodeResult::complete([
            'values' => $resumption->payload['values'] ?? [],
            'responded_by' => $resumption->payload['responded_by'] ?? null,
            'responded_by_name' => $resumption->payload['responded_by_name'] ?? null,
        ]);
    }
}
