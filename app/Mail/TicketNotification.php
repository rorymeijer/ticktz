<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Attachment;
use App\Models\EmailChannel;
use App\Models\RichTextImage;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

/**
 * Every outgoing ticket notification.
 *
 * One Mailable rather than one per event: the difference between "your ticket
 * was created" and "your ticket was answered" is the template, not the
 * plumbing, and templates are editable data.
 *
 * The Message-ID is generated rather than left to the transport, because it
 * carries the ticket key. A reply quotes it in `In-Reply-To`, which is how the
 * inbound poller threads the answer back onto the right ticket without asking
 * the customer to keep a reference number in the subject line.
 */
class TicketNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, Attachment>  $attachments
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?EmailChannel $channel,
        public readonly string $renderedSubject,
        public readonly string $renderedBody,
        /**
         * The same notification as plain text.
         *
         * Not derived from the HTML: it is rendered from the same template
         * against the flattened placeholder values, so the text part reads as
         * something a person wrote rather than as markup with the tags pulled
         * out. Required rather than defaulted — a notification has two parts,
         * and a caller that forgets one should not quietly send a stripped
         * version of the other.
         */
        public readonly string $renderedText,
        /**
         * The images this copy of the notification carries, keyed by content
         * id. Filtered per recipient before it gets here: an internal note's
         * screenshot reaches the agents it was mailed to and nobody else.
         *
         * @var array<string, RichTextImage>
         */
        public readonly array $inlineImages,
        public readonly string $messageId,
        public readonly ?string $inReplyTo = null,
        string $locale = 'en',
    ) {
        // `$locale` is Mailable's own property: setting it here means the
        // views and any `__()` inside them render in the recipient's language.
        $this->locale = $locale;
        $this->onQueue(config('ticktz.queues.mail'));

        /*
         * Streamed rather than read by path: an instance storing attachments
         * on S3 has no local file to point at, and a screenshot is not
         * something to hold in memory twice for the sake of a shorter line.
         */
        $this->withSymfonyMessage(function (Email $message): void {
            foreach ($this->inlineImages as $cid => $image) {
                $stream = Storage::disk($image->disk)->readStream($image->path);

                if ($stream === null) {
                    continue;
                }

                $message->embed($stream, $cid, $image->mime_type);
            }
        });
    }

    public function envelope(): Envelope
    {
        $envelope = new Envelope(subject: $this->renderedSubject);

        if ($this->channel) {
            $envelope = new Envelope(
                from: new Address(
                    $this->channel->address,
                    $this->channel->from_name ?: config('app.name'),
                ),
                replyTo: [new Address(
                    $this->channel->reply_to ?: $this->channel->address,
                )],
                subject: $this->renderedSubject,
            );
        }

        return $envelope;
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->inReplyTo ? [$this->inReplyTo] : [],
            text: array_filter([
                // Tells well-behaved auto-responders not to reply, which is
                // half of the loop prevention; ignore_senders is the other.
                'Auto-Submitted' => 'auto-generated',
                'X-Auto-Response-Suppress' => 'All',
                'X-Ticktz-Ticket' => $this->ticket->key,
                'In-Reply-To' => $this->inReplyTo ? "<{$this->inReplyTo}>" : null,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.ticket',
            text: 'mail.ticket-text',
            with: [
                'ticket' => $this->ticket,
                'body' => $this->renderedBody,
                'text' => $this->renderedText,
                'channel' => $this->channel,
                'locale' => (string) $this->locale,
            ],
        );
    }
}
