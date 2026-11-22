<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An email a workflow sends: a subject and a body, as plain text or HTML.
 */
class WorkflowEmail extends Mailable
{
    /**
     * @param  list<string>  $replyToAddresses
     */
    public function __construct(
        public string $subjectLine,
        public string $body,
        public bool $isHtml = false,
        public array $replyToAddresses = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: array_map(fn (string $address) => new Address($address), $this->replyToAddresses),
        );
    }

    public function content(): Content
    {
        return $this->isHtml
            ? new Content(htmlString: $this->body)
            : new Content(text: 'mail.workflow-text', with: ['body' => $this->body]);
    }
}
