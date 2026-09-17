<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\TicketNotification;
use App\Models\Comment;
use App\Models\EmailChannel;
use App\Models\EmailTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RichText\RichTextSanitizer;
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
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /**
     * Send one notification to one person.
     *
     * @param  array<string, string>  $extra  additional placeholders for this
     *                                        notification — an approval's
     *                                        subject and decision links, say
     */
    public function send(
        string $templateKey,
        Ticket $ticket,
        User $recipient,
        ?Comment $comment = null,
        ?EmailChannel $channel = null,
        array $extra = [],
    ): void {
        if (blank($recipient->email) || ! $recipient->is_active) {
            return;
        }

        $channel ??= $this->channelFor($ticket);
        $locale = $recipient->locale ?: (string) config('app.locale');

        [$subject, $html, $text] = $this->render($templateKey, $ticket, $recipient, $comment, $channel, $locale, $extra);

        $mailable = new TicketNotification(
            ticket: $ticket,
            channel: $channel,
            renderedSubject: $subject,
            renderedBody: $html,
            renderedText: $text,
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
     * @param  array<string, string>  $extra
     * @return array{0: string, 1: string, 2: string} subject, HTML body, text body
     */
    public function render(
        string $templateKey,
        Ticket $ticket,
        User $recipient,
        ?Comment $comment,
        ?EmailChannel $channel,
        string $locale,
        array $extra = [],
    ): array {
        /** @var array<string, string> $extra */
        $template = EmailTemplate::resolve($templateKey, $channel, $locale);

        $packaged = $this->packagedTemplate($templateKey, $locale);

        $subject = $template?->subject ?? $packaged['subject'];
        $body = $template?->body ?? $packaged['body'];

        // Feature placeholders win over the ticket ones: a notification that
        // has an `approval.subject` means that one, not the ticket's.
        $values = array_merge($this->placeholders($ticket, $recipient, $comment, $locale), $extra);

        return [
            EmailTemplate::render($subject, $values),
            $this->renderHtml($body, $values, $this->richPlaceholders($ticket, $comment)),
            EmailTemplate::render($body, $values),
        ];
    }

    /**
     * The two placeholders that stand for a block of somebody's writing rather
     * than a word.
     *
     * Everything else in a template — a name, a key, a status — is a phrase
     * that belongs inside a sentence, and is escaped into the HTML part like
     * any other text. These two are the rich text itself, already sanitised,
     * and go in as markup.
     *
     * @return array<string, string>
     */
    private function richPlaceholders(Ticket $ticket, ?Comment $comment): array
    {
        return array_filter([
            'ticket.description' => (string) $ticket->description,
            'comment.body' => (string) $comment?->body,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * Build the HTML part of a notification from a plain-text template.
     *
     * A template is written in a textarea by an administrator, as plain text
     * with placeholders — that is what it has always been, and making it rich
     * text would mean asking somebody editing a notification to think about
     * markup. So the template is converted the same way any other plain text
     * is: blank lines become paragraphs, single newlines become breaks, and
     * everything in it is escaped.
     *
     * The placeholders are then filled in under one rule:
     *
     *   **A placeholder alone in a paragraph is replaced by the block it
     *   stands for. A placeholder inside a sentence is replaced by its text.**
     *
     * Which is exactly how the shipped templates read — `{{ ticket.description }}`
     * sits on its own line, `{{ requester.first_name }}` sits in "Hello …" —
     * and it is what keeps a formatted reply from landing inside a paragraph
     * as `<p><p>…</p></p>`, which is invalid and which mail clients each
     * recover from differently.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $rich
     */
    private function renderHtml(string $template, array $values, array $rich): string
    {
        $html = $this->sanitizer->fromPlainText($template);

        foreach ($rich as $key => $markup) {
            $html = str_replace('<p>{{ '.$key.' }}</p>', $markup, $html);
        }

        // Whatever is left is inline, and inline means text: a description
        // referenced mid-sentence renders as the words it contains.
        return EmailTemplate::render($html, array_map(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $values,
        ));
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
            // The flattened text. The rich versions are handed to the HTML
            // part separately; see richPlaceholders().
            'ticket.description' => (string) $ticket->description_text,
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
            'comment.body' => (string) $comment?->body_text,
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
