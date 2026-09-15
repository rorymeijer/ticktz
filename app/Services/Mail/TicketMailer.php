<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\TicketNotification;
use App\Models\Comment;
use App\Models\EmailChannel;
use App\Models\EmailTemplate;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Builds and sends the outgoing half of the e-mail conversation.
 *
 * Two things here are load-bearing:
 *
 * 1. The Message-ID carries the ticket key (`ticktz.SUP-1042.17.abc@host`).
 *    A reply quotes it in `In-Reply-To`, so the poller threads the answer back
 *    onto the right ticket even when the customer rewrites the subject line.
 * 2. The recipient's own locale decides the language of the mail, not the
 *    locale of whoever triggered it.
 */
class TicketMailer
{
    /**
     * Send one notification to one person.
     */
    public function send(
        string $templateKey,
        Ticket $ticket,
        User $recipient,
        ?Comment $comment = null,
        ?EmailChannel $channel = null,
    ): void {
        if (blank($recipient->email) || ! $recipient->is_active) {
            return;
        }

        $channel ??= $this->channelFor($ticket);
        $locale = $recipient->locale ?: (string) config('app.locale');

        [$subject, $body] = $this->render($templateKey, $ticket, $recipient, $comment, $channel, $locale);

        $mailable = new TicketNotification(
            ticket: $ticket,
            channel: $channel,
            renderedSubject: $subject,
            renderedBody: $body,
            messageId: $this->messageId($ticket, $comment),
            inReplyTo: $this->inReplyTo($ticket),
            locale: $locale,
        );

        Mail::mailer($this->mailerFor($channel))
            ->to($recipient->email, $recipient->name)
            ->locale($locale)
            ->send($mailable);
    }

    /**
     * @param  iterable<int, User>  $recipients
     */
    public function sendMany(
        string $templateKey,
        Ticket $ticket,
        iterable $recipients,
        ?Comment $comment = null,
        ?EmailChannel $channel = null,
    ): void {
        $seen = [];

        foreach ($recipients as $recipient) {
            $email = mb_strtolower((string) $recipient->email);

            // The same person can be requester, watcher and assignee at once;
            // they should still get one e-mail.
            if ($email === '' || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;

            $this->send($templateKey, $ticket, $recipient, $comment, $channel);
        }
    }

    /**
     * Resolve the template (configured override, else the packaged default)
     * and fill in its placeholders.
     *
     * @return array{0: string, 1: string}
     */
    public function render(
        string $templateKey,
        Ticket $ticket,
        User $recipient,
        ?Comment $comment,
        ?EmailChannel $channel,
        string $locale,
    ): array {
        $template = EmailTemplate::resolve($templateKey, $channel, $locale);

        $packaged = $this->packagedTemplate($templateKey, $locale);

        $subject = $template?->subject ?? $packaged['subject'];
        $body = $template?->body ?? $packaged['body'];

        $values = $this->placeholders($ticket, $recipient, $comment, $locale);

        return [
            EmailTemplate::render($subject, $values),
            EmailTemplate::render($body, $values),
        ];
    }

    /**
     * The template Ticktz ships for a notification.
     *
     * The keys in the packaged mail language file contain dots of their own
     * ('ticket.created.requester'), which `trans()` cannot address with dot
     * notation, so the group is fetched whole and indexed directly.
     *
     * @return array{subject: string, body: string}
     */
    public function packagedTemplate(string $templateKey, string $locale): array
    {
        $templates = trans('mail.templates', [], $locale);
        $template = is_array($templates) ? ($templates[$templateKey] ?? []) : [];

        return [
            'subject' => (string) ($template['subject'] ?? '{{ app.name }}: {{ ticket.key }}'),
            'body' => (string) ($template['body'] ?? '{{ comment.body }}'),
        ];
    }

    /**
     * The values a template may reference. Everything is a string: a template
     * is text, and a half-rendered object in a customer's inbox is worse than
     * a blank.
     *
     * @return array<string, string>
     */
    public function placeholders(Ticket $ticket, ?User $recipient, ?Comment $comment, string $locale): array
    {
        $ticket->loadMissing(['status', 'priority', 'requester', 'assignee', 'queue']);

        return [
            'ticket.key' => $ticket->key,
            'ticket.subject' => $ticket->subject,
            'ticket.description' => (string) $ticket->description,
            'ticket.status' => (string) $ticket->status?->translatedName($locale),
            'ticket.priority' => (string) $ticket->priority?->translatedName($locale),
            'ticket.queue' => (string) ($ticket->queue?->name ?? $ticket->team?->name ?? ''),
            'ticket.portal_url' => url("/portal/requests/{$ticket->key}"),
            'ticket.agent_url' => url("/agent/tickets/{$ticket->key}"),
            'requester.name' => (string) $ticket->requester?->name,
            'requester.first_name' => $this->firstName($ticket->requester?->name),
            'requester.email' => (string) $ticket->requester?->email,
            'assignee.name' => (string) $ticket->assignee?->name,
            'recipient.name' => (string) $recipient?->name,
            'recipient.first_name' => $this->firstName($recipient?->name),
            'comment.body' => (string) $comment?->body,
            'comment.author' => (string) ($comment?->author?->name ?? __('tickets.timeline.system')),
            'app.name' => (string) config('app.name'),
        ];
    }

    /**
     * `ticktz.SUP-1042.17.9f2a@example.org` — the ticket key is part of the
     * address so the poller can recover it from `In-Reply-To` alone.
     */
    public function messageId(Ticket $ticket, ?Comment $comment = null): string
    {
        return sprintf(
            'ticktz.%s.%d.%s@%s',
            $ticket->key,
            $comment?->getKey() ?? 0,
            Str::lower(Str::random(8)),
            $this->domain(),
        );
    }

    /**
     * Extract a ticket key from a Message-ID this application generated.
     */
    public static function ticketKeyFromMessageId(string $messageId): ?string
    {
        $messageId = trim($messageId, " \t\n\r\0\x0B<>");

        if (preg_match('/^ticktz\.([A-Z0-9]+-\d+)\./i', $messageId, $matches) === 1) {
            return mb_strtoupper($matches[1]);
        }

        return null;
    }

    private function inReplyTo(Ticket $ticket): ?string
    {
        // Chain onto the most recent inbound message so mail clients group the
        // thread the way the customer expects.
        return $ticket->relationLoaded('inboundMessages')
            ? $ticket->inboundMessages->last()?->message_id
            : $ticket->inboundMessages()->latest('id')->value('message_id');
    }

    private function channelFor(Ticket $ticket): ?EmailChannel
    {
        if ($ticket->email_channel_id) {
            return EmailChannel::query()->find($ticket->email_channel_id);
        }

        return EmailChannel::query()->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Register the channel's SMTP settings as a mailer on the fly, so several
     * mailboxes can send from one instance without pre-declaring them in
     * config/mail.php.
     */
    private function mailerFor(?EmailChannel $channel): ?string
    {
        $config = $channel?->mailerConfig();

        if ($config === null) {
            return null;
        }

        $name = 'ticktz-channel-'.$channel->getKey();

        Config::set("mail.mailers.{$name}", $config);

        return $name;
    }

    private function domain(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'ticktz.local';
    }

    private function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : (explode(' ', $name)[0] ?? $name);
    }
}
