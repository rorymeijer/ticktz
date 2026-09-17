<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailChannel;
use App\Models\InboundMessage;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RichText\RichTextSanitizer;
use App\Services\SettingsRepository;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a received e-mail into a ticket or a reply.
 *
 * The contract this class exists to keep is idempotency. A mail server will
 * hand you the same message twice — a poll that timed out after processing but
 * before flagging, a worker that died mid-job, a mailbox restored from backup.
 * So the `inbound_messages` row is claimed first, inside a transaction, on a
 * unique (channel, message hash) index. If the claim fails the message has
 * already been seen and processing stops. No ticket is ever created twice.
 */
class InboundMessageProcessor
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly AuditLogger $audit,
        private readonly InboundHtmlBody $html,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /**
     * @param  array{
     *     message_id: string,
     *     in_reply_to?: string|null,
     *     references?: array<int, string>,
     *     from_address: string,
     *     from_name?: string|null,
     *     to?: array<int, string>,
     *     subject?: string|null,
     *     text?: string|null,
     *     html?: string|null,
     *     received_at?: \DateTimeInterface|null,
     *     attachments?: array<int, array{name: string, mime: string, content: string}>,
     * }  $message
     */
    public function process(EmailChannel $channel, array $message): ?InboundMessage
    {
        $messageId = trim($message['message_id'], " \t\n\r\0\x0B<>");

        if ($messageId === '') {
            // Without a Message-ID there is nothing to deduplicate on, so
            // synthesise a stable one from the content instead of risking a
            // duplicate ticket on every poll.
            $messageId = 'generated-'.hash('sha256', implode('|', [
                $channel->getKey(),
                $message['from_address'] ?? '',
                $message['subject'] ?? '',
                $message['text'] ?? '',
            ])).'@ticktz';
        }

        $record = $this->claim($channel, $messageId, $message);

        if ($record === null) {
            // Already seen. This is the normal path for a redelivery.
            return null;
        }

        try {
            return $this->handle($channel, $record, $message);
        } catch (Throwable $exception) {
            report($exception);

            $record->forceFill([
                'status' => 'failed',
                'reason' => mb_substr($exception->getMessage(), 0, 500),
                'processed_at' => now(),
            ])->save();

            return $record;
        }
    }

    /**
     * Insert the row that says "this message is mine to process", or return
     * null when another pass already claimed it.
     *
     * @param  array<string, mixed>  $message
     */
    private function claim(EmailChannel $channel, string $messageId, array $message): ?InboundMessage
    {
        $hash = InboundMessage::hashFor($messageId);

        return DB::transaction(function () use ($channel, $messageId, $hash, $message): ?InboundMessage {
            $exists = InboundMessage::query()
                ->where('email_channel_id', $channel->getKey())
                ->where('message_hash', $hash)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return null;
            }

            return InboundMessage::query()->create([
                'email_channel_id' => $channel->getKey(),
                'message_id' => mb_substr($messageId, 0, 500),
                'message_hash' => $hash,
                'in_reply_to' => isset($message['in_reply_to'])
                    ? mb_substr(trim((string) $message['in_reply_to'], ' <>'), 0, 500)
                    : null,
                'references' => $message['references'] ?? null,
                'from_address' => mb_strtolower(trim($message['from_address'])),
                'from_name' => $message['from_name'] ?? null,
                'to_addresses' => $message['to'] ?? null,
                'subject' => isset($message['subject']) ? mb_substr($message['subject'], 0, 500) : null,
                'body_text' => $message['text'] ?? null,
                'body_html' => $message['html'] ?? null,
                'status' => 'pending',
                'received_at' => $message['received_at'] ?? now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handle(EmailChannel $channel, InboundMessage $record, array $message): InboundMessage
    {
        if ($channel->ignoresSender($record->from_address)) {
            return $this->ignore($record, 'Sender is on the ignore list');
        }

        if ($this->looksAutomated($message)) {
            return $this->ignore($record, 'Automated message (auto-reply or bounce)');
        }

        $body = $this->body($message);

        if (trim($body) === '' && blank($record->subject)) {
            return $this->ignore($record, 'Empty message');
        }

        $ticket = $this->findExistingTicket($record);
        $author = $this->resolveSender($channel, $record);

        if ($author === null) {
            return $this->ignore($record, 'Unknown sender and auto-provisioning is off');
        }

        if ($ticket) {
            return $this->appendReply($record, $ticket, $author, $body);
        }

        return $this->openTicket($channel, $record, $author, $body);
    }

    /**
     * Which ticket does this message belong to?
     *
     * In order of reliability: a Message-ID this application generated (it
     * carries the key), then a ticket key in the subject, then an earlier
     * inbound message in the same thread.
     */
    private function findExistingTicket(InboundMessage $record): ?Ticket
    {
        $candidates = array_values(array_filter(array_map(
            static fn ($reference): string => trim((string) $reference, " \t\n\r\0\x0B<>"),
            [$record->in_reply_to, ...($record->references ?? [])],
        )));

        foreach ($candidates as $reference) {
            $key = TicketMailer::ticketKeyFromMessageId((string) $reference);

            if ($key && $ticket = Ticket::query()->where('key', $key)->first()) {
                return $ticket;
            }
        }

        if ($record->subject && preg_match('/\[([A-Z][A-Z0-9]*-\d+)\]/', $record->subject, $matches) === 1) {
            if ($ticket = Ticket::query()->where('key', mb_strtoupper($matches[1]))->first()) {
                return $ticket;
            }
        }

        foreach ($candidates as $reference) {
            $ticketId = InboundMessage::query()
                ->where('message_hash', InboundMessage::hashFor((string) $reference))
                ->whereNotNull('ticket_id')
                ->value('ticket_id');

            if ($ticketId && $ticket = Ticket::query()->find($ticketId)) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Find or create the person who wrote. An address nobody recognises
     * becomes a requester account, which is how a service desk that publishes
     * an e-mail address is supposed to work.
     */
    private function resolveSender(EmailChannel $channel, InboundMessage $record): ?User
    {
        $user = User::query()->where('email', $record->from_address)->first();

        if ($user) {
            return $user->is_active ? $user : null;
        }

        if (! $channel->auto_provision_requesters) {
            return null;
        }

        $name = trim((string) $record->from_name) ?: Str::before($record->from_address, '@');

        $user = User::query()->create([
            'name' => $name,
            'email' => $record->from_address,
            'locale' => config('app.locale'),
            'directory' => 'local',
            'is_active' => true,
            'organization_id' => Organization::matchingEmail($record->from_address)?->getKey(),
        ]);

        $role = Role::query()->where('is_default', true)->first()
            ?? Role::query()->where('name', Role::REQUESTER)->first();

        if ($role) {
            $user->roles()->attach($role);
        }

        $this->audit->as('email', $record->from_address)
            ->created($user, "Requester auto-provisioned from an inbound e-mail to {$channel->address}");

        return $user;
    }

    private function openTicket(EmailChannel $channel, InboundMessage $record, User $author, string $body): InboundMessage
    {
        $subject = $this->cleanSubject($record->subject) ?: __('tickets.placeholders.subject');

        $ticket = $this->tickets->create([
            'subject' => $subject,
            'description' => $body,
            'requester_id' => $author->getKey(),
            'queue_id' => $channel->queue_id,
            'team_id' => $channel->team_id,
            'request_type_id' => $channel->request_type_id,
            'priority_id' => $channel->priority_id,
            'email_channel_id' => $channel->getKey(),
            'source' => 'email',
        ], $author);

        $record->forceFill([
            'status' => 'processed',
            'ticket_id' => $ticket->getKey(),
            'processed_at' => now(),
        ])->save();

        return $record;
    }

    private function appendReply(InboundMessage $record, Ticket $ticket, User $author, string $body): InboundMessage
    {
        // A reply from anyone but an agent is the requester talking, and it is
        // always public — there is no way to write an internal note by e-mail.
        $comment = $this->tickets->comment(
            $ticket,
            $body,
            $author,
            internal: false,
            source: 'email',
            messageId: $record->message_id,
        );

        $record->forceFill([
            'status' => 'processed',
            'ticket_id' => $ticket->getKey(),
            'comment_id' => $comment->getKey(),
            'processed_at' => now(),
        ])->save();

        $this->reopenIfNeeded($ticket, $author);

        return $record;
    }

    /**
     * A reply to a resolved ticket means it was not resolved. Reopening is
     * bounded by a setting so a "thanks!" three months later does not revive
     * a closed case.
     */
    private function reopenIfNeeded(Ticket $ticket, User $author): void
    {
        $ticket->refresh()->loadMissing(['status', 'workflow.transitions.toStatus', 'workflow.statuses']);

        if (! $ticket->status->isResolved()) {
            return;
        }

        $window = (int) app(SettingsRepository::class)->int('tickets.reopen_window_days', 14);
        $resolvedAt = $ticket->resolved_at ?? $ticket->closed_at;

        if ($window <= 0 || ($resolvedAt && $resolvedAt->lt(now()->subDays($window)))) {
            return;
        }

        $target = $ticket->workflow
            ->transitionsFrom($ticket->status)
            ->map(fn ($transition) => $transition->toStatus)
            ->first(fn ($status) => $status?->category === TicketStatus::CATEGORY_OPEN);

        if ($target) {
            $this->tickets->transition($ticket, $target, $author);
        }
    }

    private function ignore(InboundMessage $record, string $reason): InboundMessage
    {
        $record->forceFill([
            'status' => 'ignored',
            'reason' => $reason,
            'processed_at' => now(),
        ])->save();

        return $record;
    }

    /**
     * Out-of-office replies and bounces must never create a ticket: the ticket
     * would send a confirmation, which would bounce, which would create a
     * ticket.
     *
     * @param  array<string, mixed>  $message
     */
    private function looksAutomated(array $message): bool
    {
        $headers = array_change_key_case($message['headers'] ?? [], CASE_LOWER);

        $autoSubmitted = mb_strtolower((string) ($headers['auto-submitted'] ?? ''));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if (isset($headers['x-autoreply']) || isset($headers['x-autorespond'])) {
            return true;
        }

        // RFC 3834 says a bounce has an empty envelope sender; not every
        // server exposes that, so the precedence header is a second signal.
        return in_array(mb_strtolower((string) ($headers['precedence'] ?? '')), ['bulk', 'auto_reply', 'junk'], true);
    }

    /**
     * What the person actually wrote, as rich text.
     *
     * The HTML part first, because that is where the formatting is: a customer
     * who sent a numbered list should arrive with a numbered list rather than
     * four lines that start with digits.
     *
     * The text part is the fallback, and not a rare one — plenty of mail is
     * sent as text, and some HTML mail turns out to be nothing but a quoted
     * thread once the reply has been taken out of it. Either way the result is
     * the same shape as anything typed into the editor.
     *
     * Nothing here is what makes the body safe. Both paths go through
     * RichTextSanitizer on the way into the database, which is also what drops
     * the remote images a mail signature carries — those are tracking pixels
     * pointed at whoever opens the ticket.
     *
     * @param  array<string, mixed>  $message
     */
    private function body(array $message): string
    {
        $html = trim((string) ($message['html'] ?? ''));

        if ($html !== '') {
            $reply = $this->html->extract($html);

            if ($this->sanitizer->clean($reply, images: true) !== '') {
                return $reply;
            }
        }

        return $this->sanitizer->fromPlainText($this->plainBody($message));
    }

    /**
     * The same, flattened, for the mail that has no HTML part — and for one
     * whose HTML part turns out to hold nothing.
     *
     * Converting the HTML here rather than keeping it is deliberate: this path
     * exists for a body the rich one could not use, and a second attempt at
     * the same markup would fail the same way.
     *
     * @param  array<string, mixed>  $message
     */
    private function plainBody(array $message): string
    {
        $text = trim((string) ($message['text'] ?? ''));

        if ($text !== '') {
            return $this->stripQuotedHistory($text);
        }

        $html = (string) ($message['html'] ?? '');

        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", $html) ?? $html;

        return $this->stripQuotedHistory(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * Cut the quoted thread off the bottom of a reply.
     *
     * Deliberately conservative: only the unambiguous separators that mail
     * clients actually emit. Over-trimming loses what the customer wrote,
     * which is worse than a little extra quoting.
     */
    private function stripQuotedHistory(string $body): string
    {
        $markers = [
            '/^-----\s*Original Message\s*-----$/im',
            '/^-----\s*Oorspronkelijk bericht\s*-----$/im',
            '/^_{10,}$/m',
            '/^On .{5,80} wrote:$/im',
            '/^Op .{5,80} schreef .{0,120}:$/im',
            '/^Van:\s.+$/im',
            '/^From:\s.+$/im',
        ];

        $cut = mb_strlen($body);

        foreach ($markers as $pattern) {
            if (preg_match($pattern, $body, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $position = mb_strlen(substr($body, 0, $matches[0][1]));

                // Only trim when something of the reply survives.
                if ($position > 0 && $position < $cut) {
                    $cut = $position;
                }
            }
        }

        $trimmed = rtrim(mb_substr($body, 0, $cut));

        return $trimmed === '' ? trim($body) : $trimmed;
    }

    /**
     * "Re: Fwd: RE: [SUP-12] Printer broken" -> "Printer broken".
     */
    private function cleanSubject(?string $subject): string
    {
        $subject = trim((string) $subject);
        $subject = preg_replace('/\[[A-Z][A-Z0-9]*-\d+\]/', '', $subject) ?? $subject;

        do {
            $before = $subject;
            $subject = preg_replace('/^\s*(re|fw|fwd|aw|antw)\s*(\[\d+\])?\s*:\s*/i', '', $subject) ?? $subject;
        } while ($subject !== $before);

        return mb_substr(trim($subject), 0, 500);
    }

    /**
     * @param  array<int, mixed>  $addresses
     * @return array<int, string>
     */
    public static function normaliseAddresses(array $addresses): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($address) => is_string($address) ? mb_strtolower(trim($address)) : null,
            Arr::flatten($addresses),
        ))));
    }
}
