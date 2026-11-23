<?php

namespace App\Mail;

use App\Models\WorkflowFile;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An email a workflow sends: a subject and a body, as plain text or HTML,
 * with any workflow files attached.
 */
class WorkflowEmail extends Mailable
{
    /**
     * @param  list<string>  $replyToAddresses
     * @param  list<WorkflowFile>  $files
     */
    public function __construct(
        public string $subjectLine,
        public string $body,
        public bool $isHtml = false,
        public array $replyToAddresses = [],
        public array $files = [],
    ) {}

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (WorkflowFile $file) => Attachment::fromStorageDisk($file->disk, $file->path)->as($file->name)->withMime($file->mime),
            $this->files,
        );
    }

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
