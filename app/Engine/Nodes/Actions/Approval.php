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
 * Pauses the flow until enough of the named approvers approve, continuing
 * down "approved", or until so many reject that the number needed can no
 * longer be reached, continuing down "rejected". Assignments decides, so
 * the step resumes once. With no decision by the timeout (48 hours by
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
            new Field('approvers', __('nodes.approval.approvers'), Field::USERS, required: true),
            new Field('required_approvals', __('nodes.approval.approvals_needed'), Field::NUMBER, required: true, default: 1, description: __('nodes.approval.how_many_approvers_must_approve')),
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
            new Output('responded_by', __('nodes.approval.responded_by_user_id'), 'string'),
            new Output('responded_by_name', __('nodes.approval.responded_by'), 'string'),
            new Output('approvals', __('nodes.approval.approvals'), 'number', __('nodes.approval.how_many_approved')),
            new Output('rejections', __('nodes.approval.rejections'), 'number', __('nodes.approval.how_many_rejected')),
            new Output('responses', __('nodes.approval.responses'), 'array', __('nodes.approval.each_answer_with_decision')),
        ];
    }

    public function ports(array $config = []): array
    {
        return ['approved', 'rejected'];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $approvers = collect((array) $context->input('approvers'))
            ->map(fn (mixed $id) => $this->assignments->activeUser($id))
            ->filter()
            ->unique('id')
            ->values();

        if ($approvers->isEmpty()) {
            return NodeResult::fail(__('nodes.approval.approver_does_not_have_an'), retryable: false);
        }

        $required = max(1, (int) $context->input('required_approvals', 1));

        if ($required > $approvers->count()) {
            return NodeResult::fail(__('nodes.approval.needs_n_approvals_but_only', ['needed' => $required, 'count' => $approvers->count()]), retryable: false);
        }

        $dueAt = now()->addHours(max(1, (int) $context->input('timeout_hours', 48)));
        $title = (string) $context->input('title');
        $message = $this->text($context->input('message'));

        $ids = $approvers->map(fn ($approver) => $this->assignments->create(
            $context, AssignmentType::Approval, $approver, $title, $message, $dueAt,
        )->id)->all();

        return NodeResult::waitForResponse($dueAt, ['assignment_ids' => $ids, 'required' => $required]);
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
            'approvals' => $resumption->payload['approvals'] ?? ($decision === 'approved' ? 1 : 0),
            'rejections' => $resumption->payload['rejections'] ?? ($decision === 'rejected' ? 1 : 0),
            'responses' => $resumption->payload['responses'] ?? [],
        ], $decision);
    }

    protected function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (is_scalar($value) ? (string) $value : (string) json_encode($value));
    }
}
