<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\Assignments;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Notifies people in the app, in their inbox, and optionally
 * by email through the organization's mail settings. Without a link, the
 * notification and the email open the run that sent them, even for
 * recipients who cannot view runs.
 */
class SendNotification extends Node
{
    public function __construct(protected Assignments $assignments) {}

    public static function type(): string
    {
        return 'core.send_notification';
    }

    public function label(): string
    {
        return __('nodes.send_notification.send_notification');
    }

    public function description(): string
    {
        return __('nodes.send_notification.notifies_people_in_the_app');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('common.messaging');
    }

    public function icon(): string
    {
        return 'bell-alert';
    }

    public function inputs(): array
    {
        return [
            new Field('recipients', __('nodes.send_notification.recipients'), Field::USERS, required: true),
            new Field('title', __('common.title'), Field::TEXT, required: true, templated: true),
            new Field('message', __('nodes.send_notification.message'), Field::TEXTAREA, templated: true),
            new Field('link', __('nodes.send_notification.link'), Field::TEXT, templated: true, description: __('nodes.send_notification.where_opening_the_notification_goes')),
            new Field('email', __('nodes.send_notification.also_send_by_email'), Field::BOOLEAN, default: false),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('recipients', __('nodes.send_notification.recipients'), 'array', __('nodes.send_notification.ids_of_the_people_notified')),
            new Output('count', __('nodes.send_notification.count'), 'number'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $users = collect((array) $context->input('recipients'))
            ->map(fn (mixed $id) => $this->assignments->activeUser($id))
            ->filter()
            ->unique('id')
            ->values();

        if ($users->isEmpty()) {
            return NodeResult::fail(__('nodes.send_notification.none_of_the_recipients_has'), retryable: false);
        }

        $link = trim((string) $context->input('link'));

        if ($link !== '' && filter_var($link, FILTER_VALIDATE_URL) === false) {
            return NodeResult::fail(__('nodes.send_notification.link_is_not_a_valid', ['link' => $link]), retryable: false);
        }

        $link = $link !== '' ? $link : route('workspaces.executions.show', [$context->workspace(), $context->execution]);
        $title = (string) $context->input('title');
        $message = $context->input('message');
        $message = $message === null || $message === '' ? null : (string) $message;
        $workspaceId = $context->workspace()->id;
        $output = ['recipients' => $users->pluck('id')->all(), 'count' => $users->count()];

        Notification::send(
            $users->filter(fn (User $user) => $user->wantsNotification(NotificationKind::Workflow, 'database')),
            new WorkflowNotification($title, $message, $link, $workspaceId),
        );

        if ($context->input('email')) {
            try {
                Notification::send(
                    $users->filter(fn (User $user) => $user->wantsNotification(NotificationKind::Workflow, 'mail')),
                    new WorkflowNotification($title, $message, $link, $workspaceId, ['mail']),
                );
            } catch (TransportExceptionInterface $exception) {
                return NodeResult::fail(__('nodes.send_notification.notified_in_the_app_but', ['message' => $exception->getMessage()]), retryable: false, output: $output);
            }
        }

        return NodeResult::complete($output);
    }
}
