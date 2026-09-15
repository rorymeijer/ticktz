<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailChannel;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Reads a mailbox over IMAP and hands each message to the processor.
 *
 * Kept deliberately thin: everything that decides what a message *means* lives
 * in InboundMessageProcessor, which is testable without a mail server. This
 * class only knows how to talk IMAP and how to flag a message once it has been
 * dealt with.
 */
class MailboxPoller
{
    public function __construct(private readonly InboundMessageProcessor $processor) {}

    /**
     * @return array{fetched: int, processed: int, ignored: int, skipped: int, failed: int}
     */
    public function poll(EmailChannel $channel, int $limit = 50): array
    {
        $stats = ['fetched' => 0, 'processed' => 0, 'ignored' => 0, 'skipped' => 0, 'failed' => 0];

        $client = (new ClientManager)->make($channel->imapConfig());
        $client->connect();

        try {
            $folder = $client->getFolderByPath($channel->imap_folder ?: 'INBOX');

            $messages = $folder->query()
                ->unseen()
                ->leaveUnread()
                ->limit($limit)
                ->get();

            foreach ($messages as $message) {
                $stats['fetched']++;

                try {
                    $record = $this->processor->process($channel, $this->normalise($message));

                    match ($record?->status) {
                        'processed' => $stats['processed']++,
                        'ignored' => $stats['ignored']++,
                        'failed' => $stats['failed']++,
                        // A null record means the message was already seen.
                        default => $stats['skipped']++,
                    };

                    // Only flag after the database says the message is
                    // handled. Crashing before this point costs a re-read;
                    // flagging first would cost a lost ticket.
                    $this->finish($channel, $message);
                } catch (Throwable $exception) {
                    $stats['failed']++;

                    Log::error('Failed to process an inbound message on [{channel}]: {message}', [
                        'channel' => $channel->slug,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            $client->disconnect();
        }

        $channel->forceFill([
            'last_polled_at' => now(),
            'last_poll_result' => sprintf(
                '%d fetched, %d processed, %d ignored, %d already seen, %d failed',
                $stats['fetched'],
                $stats['processed'],
                $stats['ignored'],
                $stats['skipped'],
                $stats['failed'],
            ),
        ])->save();

        return $stats;
    }

    /**
     * Flatten a php-imap message into the plain array the processor expects,
     * so nothing downstream depends on the IMAP library.
     *
     * @return array<string, mixed>
     */
    public function normalise(Message $message): array
    {
        $from = $message->getFrom()[0] ?? null;

        return [
            'message_id' => (string) $message->getMessageId(),
            'in_reply_to' => (string) $message->getInReplyTo(),
            'references' => array_values(array_filter(array_map(
                static fn ($reference) => trim((string) $reference, ' <>'),
                preg_split('/\s+/', (string) $message->getReferences()) ?: [],
            ))),
            'from_address' => mb_strtolower((string) ($from->mail ?? '')),
            'from_name' => $from->personal ?? null,
            'to' => InboundMessageProcessor::normaliseAddresses(
                array_map(static fn ($address) => $address->mail ?? null, $message->getTo()->toArray())
            ),
            'subject' => (string) $message->getSubject(),
            'text' => $message->getTextBody(),
            'html' => $message->getHTMLBody(),
            'headers' => $this->headers($message),
            'received_at' => $message->getDate()?->first()?->toDate(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(Message $message): array
    {
        $headers = [];

        foreach (['auto-submitted', 'precedence', 'x-autoreply', 'x-autorespond', 'return-path'] as $name) {
            $value = $message->getHeader()->get($name);

            if ($value !== null && (string) $value !== '') {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Mark the message as dealt with, in whichever way the channel is set up:
     * move it, delete it, or just flag it seen.
     */
    private function finish(EmailChannel $channel, Message $message): void
    {
        try {
            $message->setFlag('Seen');

            if ($channel->imap_delete_after_processing) {
                $message->delete();

                return;
            }

            if (filled($channel->imap_processed_folder)) {
                $message->move($channel->imap_processed_folder);
            }
        } catch (Throwable $exception) {
            // The ticket exists either way; a failed flag only means the next
            // poll re-reads the message and the processor deduplicates it.
            Log::warning('Could not flag an inbound message on [{channel}]: {message}', [
                'channel' => $channel->slug,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
