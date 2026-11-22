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
 * Pauses the flow until a named user approves or rejects, continuing down
 * "approved" or "rejected". With no answer by the timeout (48 hours by
 * default), the step fails, which an error output can catch.
 */
class Approval extends Node
{
    public function __construct(protected Assignments $assignments) {}

    public static function type(): string
    {
        return 'core.approval';
    }

    public function label(): string
    {
        return __('common.approval');
    }

    public function description(): string
    {
        return __('nodes.approval.pauses_the_flow_until_a');
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
        return 'hand-thumb-up';
    }

    public function inputs(): array
    {
        return [
            new Field('assignee', __('nodes.approval.approver'), Field::USER, required: true),
            new Field('title', __('common.title'), Field::TEXT, required: true, templated: true),
            new Field('message', __('common.details'), Field::TEXTAREA, templated: true),
            new Field('timeout_hours', __('common.timeout_hours'), Field::NUMBER, default: 48),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('decision', __('nodes.approval.decision'), 'string', __('nodes.approval.approved_or_rejected')),
            new Output('comment', __('nodes.approval.comment'), 'string'),
            new Output('responded_by', __('nodes.approval.responded_by_user_id'), 'number'),
            new Output('responded_by_name', __('nodes.approval.responded_by'), 'string'),
        ];
    }

    public function ports(array $config = []): array
    {
        return ['approved', 'rejected'];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $assignee = $this->assignments->activeUser($context->input('assignee'));

        if (! $assignee) {
            return NodeResult::fail(__('nodes.approval.approver_does_not_have_an'), retryable: false);
        }

        $dueAt = now()->addHours(max(1, (int) $context->input('timeout_hours', 48)));

        $assignment = $this->assignments->create(
            $context, AssignmentType::Approval, $assignee,
            (string) $context->input('title'), $this->text($context->input('message')), $dueAt,
        );

        return NodeResult::waitForResponse($dueAt, ['assignment_id' => $assignment->id]);
    }

    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        if ($resumption->reason === ResumeReason::TimerElapsed) {
            $this->assignments->expireFromState($context->state());

            return NodeResult::fail(__('nodes.approval.no_response_before_the_approval'), retryable: false);
        }

        $decision = ($resumption->payload['decision'] ?? null) === 'approved' ? 'approved' : 'rejected';

        return NodeResult::complete([
            'decision' => $decision,
            'comment' => $resumption->payload['comment'] ?? null,
            'responded_by' => $resumption->payload['responded_by'] ?? null,
            'responded_by_name' => $resumption->payload['responded_by_name'] ?? null,
        ], $decision);
    }

    protected function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (is_scalar($value) ? (string) $value : (string) json_encode($value));
    }
}
