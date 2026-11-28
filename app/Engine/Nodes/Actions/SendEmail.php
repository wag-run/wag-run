<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Mail\WorkflowEmail;
use App\Services\Mailers;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Sends an email through the organization's mail settings, or through a
 * workspace SMTP server chosen under Authentication.
 */
class SendEmail extends Node
{
    public function __construct(protected Mailers $mailers) {}

    public static function type(): string
    {
        return 'core.send_email';
    }

    public function label(): string
    {
        return __('nodes.send_email.send_email');
    }

    public function description(): string
    {
        return __('nodes.send_email.sends_an_email');
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
        return 'envelope';
    }

    public function inputs(): array
    {
        return [
            new Field('to', __('nodes.send_email.to'), Field::TEXT, required: true, templated: true, description: __('nodes.send_email.separate_addresses_with_commas')),
            new Field('cc', __('nodes.send_email.cc'), Field::TEXT, templated: true),
            new Field('bcc', __('nodes.send_email.bcc'), Field::TEXT, templated: true),
            new Field('reply_to', __('nodes.send_email.reply_to'), Field::TEXT, templated: true),
            new Field('subject', __('nodes.send_email.subject'), Field::TEXT, required: true, templated: true),
            new Field('format', __('nodes.send_email.format'), Field::SELECT, default: 'text', options: [
                'text' => __('nodes.send_email.plain_text'),
                'html' => __('nodes.send_email.html'),
            ]),
            new Field('body', __('nodes.send_email.body'), Field::TEXTAREA, required: true, templated: true),
            new Field('attachments', __('nodes.send_email.attachments'), Field::TEXT, templated: true, description: __('nodes.send_email.attachments_help')),
            new Field('credential', __('nodes.send_email.smtp_server'), Field::CREDENTIAL, credentialTypes: ['smtp'], description: __('nodes.send_email.leave_empty_to_use_the')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('to', __('nodes.send_email.to'), 'array', __('nodes.send_email.the_addresses_it_was_sent_to')),
            new Output('subject', __('nodes.send_email.subject'), 'string'),
            new Output('message_id', __('nodes.send_email.message_id'), 'string'),
        ];
    }

    public function timeout(): int
    {
        return 120;
    }

    public function execute(NodeContext $context): NodeResult
    {
        $to = self::addresses($context->input('to'));
        $cc = self::addresses($context->input('cc'));
        $bcc = self::addresses($context->input('bcc'));
        $replyTo = self::addresses($context->input('reply_to'));

        if ($to === []) {
            return NodeResult::fail(__('nodes.send_email.add_at_least_one_recipient'), retryable: false);
        }

        $invalid = array_values(array_filter([...$to, ...$cc, ...$bcc, ...$replyTo], fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) === false));

        if ($invalid !== []) {
            return NodeResult::fail(__('nodes.send_email.not_a_valid_email_address', ['address' => $invalid[0]]), retryable: false);
        }

        $mailer = $this->mailerFor($context);

        if ($mailer === null) {
            return NodeResult::fail(__('nodes.send_email.smtp_server_not_found'), retryable: false);
        }

        $subject = (string) $context->input('subject');

        try {
            $attachments = $context->files($context->input('attachments'));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        try {
            $sent = Mail::mailer($mailer)->to($to)->cc($cc)->bcc($bcc)->send(new WorkflowEmail(
                $subject,
                (string) $context->input('body'),
                $context->input('format') === 'html',
                $replyTo,
                $attachments,
            ));
        } catch (TransportExceptionInterface $exception) {
            return NodeResult::fail(__('nodes.send_email.could_not_send', ['message' => $exception->getMessage()]));
        }

        return NodeResult::complete([
            'to' => $to,
            'subject' => $subject,
            'message_id' => $sent?->getMessageId(),
        ]);
    }

    /**
     * Split a comma, semicolon or newline separated list of addresses.
     *
     * @return list<string>
     */
    public static function addresses(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[,;\n]+/', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($part) => trim((string) $part), $parts ?: []), fn (string $part) => $part !== '')));
    }

    protected function mailerFor(NodeContext $context): ?string
    {
        $key = $context->input('credential');

        if (blank($key)) {
            return $this->mailers->forOrganization($context->workspace()->organization);
        }

        $credential = $context->credential((string) $key);

        if (! $credential || $credential->type !== 'smtp') {
            return null;
        }

        return $this->mailers->register("workspace_smtp_{$credential->id}", $credential->config);
    }
}
