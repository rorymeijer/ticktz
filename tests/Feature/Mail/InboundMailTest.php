<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\EmailChannel;
use App\Models\InboundMessage;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Mail\InboundMessageProcessor;
use App\Services\Mail\TicketMailer;
use App\Services\SettingsRepository;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();

    $this->channel = EmailChannel::factory()->reading()->create([
        'address' => 'servicedesk@ticktz.test',
    ]);

    $this->processor = app(InboundMessageProcessor::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function inboundMail(array $overrides = []): array
{
    return array_merge([
        'message_id' => '<'.uniqid('msg', true).'@example.test>',
        'from_address' => 'nina@example.test',
        'from_name' => 'Nina de Vries',
        'to' => ['servicedesk@ticktz.test'],
        'subject' => 'Printer on the second floor is offline',
        'text' => "It has been offline since this morning.\n\nThanks, Nina",
        'headers' => [],
    ], $overrides);
}

it('turns a message into a ticket', function (): void {
    $record = $this->processor->process($this->channel, inboundMail());

    expect($record->status)->toBe('processed')
        ->and($record->ticket_id)->not->toBeNull();

    $ticket = Ticket::query()->findOrFail($record->ticket_id);

    expect($ticket->subject)->toBe('Printer on the second floor is offline')
        ->and($ticket->description)->toContain('offline since this morning')
        ->and($ticket->source)->toBe('email')
        ->and($ticket->email_channel_id)->toBe($this->channel->getKey())
        ->and($ticket->requester->email)->toBe('nina@example.test');
});

it('routes inbound mail the way the mailbox is configured', function (): void {
    $team = Team::factory()->create();
    $this->channel->update([
        'team_id' => $team->getKey(),
        'priority_id' => priority('high')->getKey(),
    ]);

    $record = $this->processor->process($this->channel->fresh(), inboundMail());
    $ticket = Ticket::query()->findOrFail($record->ticket_id);

    expect($ticket->team_id)->toBe($team->getKey())
        ->and($ticket->priority_id)->toBe(priority('high')->getKey());
});

/**
 * The contract the whole design exists for: a mail server WILL hand you the
 * same message twice.
 */
it('creates one ticket when the same message is delivered twice', function (): void {
    $message = inboundMail();

    $first = $this->processor->process($this->channel, $message);
    $second = $this->processor->process($this->channel, $message);

    expect($first->status)->toBe('processed')
        ->and($second)->toBeNull()
        ->and(Ticket::query()->count())->toBe(1)
        ->and(InboundMessage::query()->count())->toBe(1);
});

it('deduplicates a message that arrives without a message id', function (): void {
    $message = inboundMail(['message_id' => '']);

    $this->processor->process($this->channel, $message);
    $this->processor->process($this->channel, $message);

    expect(Ticket::query()->count())->toBe(1);
});

it('threads a reply onto the ticket by message id', function (): void {
    $opened = $this->processor->process($this->channel, inboundMail());
    $ticket = Ticket::query()->findOrFail($opened->ticket_id);

    $reply = $this->processor->process($this->channel, inboundMail([
        'subject' => 'Re: a subject the customer rewrote entirely',
        'in_reply_to' => '<ticktz.'.$ticket->key.'.0.abcd1234@ticktz.test>',
        'text' => 'It is working again now.',
    ]));

    expect($reply->ticket_id)->toBe($ticket->getKey())
        ->and(Ticket::query()->count())->toBe(1)
        ->and($ticket->comments()->latest('id')->first()->body)->toContain('working again');
});

it('threads a reply onto the ticket by the key in the subject', function (): void {
    $opened = $this->processor->process($this->channel, inboundMail());
    $ticket = Ticket::query()->findOrFail($opened->ticket_id);

    $reply = $this->processor->process($this->channel, inboundMail([
        'subject' => "Re: [{$ticket->key}] Printer on the second floor is offline",
        'text' => 'Any news?',
    ]));

    expect($reply->ticket_id)->toBe($ticket->getKey())
        ->and(Ticket::query()->count())->toBe(1);
});

it('threads a reply onto the ticket by an earlier message in the same thread', function (): void {
    $opened = $this->processor->process($this->channel, inboundMail([
        'message_id' => '<original@example.test>',
    ]));

    $reply = $this->processor->process($this->channel, inboundMail([
        'subject' => 'Fwd: something else entirely',
        'references' => ['<original@example.test>'],
        'text' => 'Adding a colleague.',
    ]));

    expect($reply->ticket_id)->toBe($opened->ticket_id)
        ->and(Ticket::query()->count())->toBe(1);
});

it('files a reply as a public comment, never an internal note', function (): void {
    $opened = $this->processor->process($this->channel, inboundMail());
    $ticket = Ticket::query()->findOrFail($opened->ticket_id);

    $this->processor->process($this->channel, inboundMail([
        'subject' => "Re: [{$ticket->key}] Printer",
        'text' => 'One more detail.',
    ]));

    $comment = $ticket->comments()->latest('id')->first();

    expect($comment->is_internal)->toBeFalse()
        ->and($comment->source)->toBe('email');
});

it('reopens a resolved ticket when the requester writes back', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);
    $ticket = Ticket::factory()->forRequester($requester)->withStatus('open')->create();

    app(TicketService::class)->transition($ticket, status('resolved'), makeAgent());

    $this->processor->process($this->channel, inboundMail([
        'subject' => "Re: [{$ticket->key}] Printer",
        'text' => 'It is happening again.',
    ]));

    expect($ticket->fresh()->status->category)->toBe(TicketStatus::CATEGORY_OPEN);
});

it('leaves a long-resolved ticket closed and files the reply as a comment', function (): void {
    app(SettingsRepository::class)->set('tickets.reopen_window_days', 7);

    $requester = makeRequester(['email' => 'nina@example.test']);
    $ticket = Ticket::factory()->forRequester($requester)->withStatus('open')->create();

    app(TicketService::class)->transition($ticket, status('resolved'), makeAgent());
    $ticket->forceFill(['resolved_at' => now()->subDays(30)])->save();

    $this->processor->process($this->channel, inboundMail([
        'subject' => "Re: [{$ticket->key}] Printer",
        'text' => 'Thanks for sorting this out months ago.',
    ]));

    expect($ticket->fresh()->status->category)->toBe(TicketStatus::CATEGORY_RESOLVED)
        ->and($ticket->comments()->count())->toBe(1);
});

it('creates a requester account for an address it does not know', function (): void {
    $record = $this->processor->process($this->channel, inboundMail());

    $user = User::query()->where('email', 'nina@example.test')->sole();

    expect($user->name)->toBe('Nina de Vries')
        ->and($user->isRequester())->toBeTrue()
        ->and($record->status)->toBe('processed');
});

it('records who created an auto-provisioned account', function (): void {
    $this->processor->process($this->channel, inboundMail());

    $user = User::query()->where('email', 'nina@example.test')->sole();

    $entry = AuditLogEntry::query()
        ->where('auditable_type', $user->getMorphClass())
        ->where('auditable_id', $user->getKey())
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_label)->toBe('nina@example.test');
});

it('drops mail from an unknown sender when auto-provisioning is off', function (): void {
    $this->channel->update(['auto_provision_requesters' => false]);

    $record = $this->processor->process($this->channel->fresh(), inboundMail());

    expect($record->status)->toBe('ignored')
        ->and(Ticket::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'nina@example.test')->exists())->toBeFalse();
});

it('reuses the existing account when the sender is already known', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test', 'name' => 'Nina de Vries']);

    $record = $this->processor->process($this->channel, inboundMail());
    $ticket = Ticket::query()->findOrFail($record->ticket_id);

    expect($ticket->requester_id)->toBe($requester->getKey())
        ->and(User::query()->where('email', 'nina@example.test')->count())->toBe(1);
});

it('drops mail from a deactivated account', function (): void {
    makeRequester(['email' => 'nina@example.test', 'is_active' => false]);

    $record = $this->processor->process($this->channel, inboundMail());

    expect($record->status)->toBe('ignored')
        ->and(Ticket::query()->count())->toBe(0);
});

/**
 * A bounce that creates a ticket sends a confirmation, which bounces, which
 * creates a ticket. The loop has to be cut here.
 */
it('ignores bounces and auto-responders', function (string $label, array $overrides): void {
    $record = $this->processor->process($this->channel, inboundMail($overrides));

    expect($record->status)->toBe('ignored')
        ->and(Ticket::query()->count())->toBe(0);
})->with([
    ['bounce handler', ['from_address' => 'MAILER-DAEMON@example.test']],
    ['no-reply address', ['from_address' => 'no-reply@example.test']],
    ['auto-submitted header', ['headers' => ['Auto-Submitted' => 'auto-replied']]],
    ['out of office', ['headers' => ['X-Autoreply' => 'yes']]],
    ['bulk precedence', ['headers' => ['Precedence' => 'bulk']]],
]);

it('honours the ignore list configured on the mailbox', function (): void {
    $this->channel->update(['ignore_senders' => ['*@newsletter.example']]);

    $record = $this->processor->process($this->channel->fresh(), inboundMail([
        'from_address' => 'weekly@newsletter.example',
    ]));

    expect($record->status)->toBe('ignored')
        ->and(Ticket::query()->count())->toBe(0);
});

it('ignores an empty message', function (): void {
    $record = $this->processor->process($this->channel, inboundMail([
        'subject' => '',
        'text' => '   ',
    ]));

    expect($record->status)->toBe('ignored');
});

it('falls back to the html part when there is no plain text', function (): void {
    $record = $this->processor->process($this->channel, inboundMail([
        'text' => null,
        'html' => '<p>The lift is stuck.</p><script>alert(1)</script><p>Floor 3.</p>',
    ]));

    $ticket = Ticket::query()->findOrFail($record->ticket_id);

    expect($ticket->description)->toContain('The lift is stuck.')
        ->and($ticket->description)->toContain('Floor 3.')
        ->and($ticket->description)->not->toContain('alert(1)');
});

it('trims the quoted thread off a reply', function (): void {
    $opened = $this->processor->process($this->channel, inboundMail());
    $ticket = Ticket::query()->findOrFail($opened->ticket_id);

    $this->processor->process($this->channel, inboundMail([
        'subject' => "Re: [{$ticket->key}] Printer",
        'text' => "Still broken.\n\nOn Tue 3 Jun 2025 at 09:12, Service Desk wrote:\n> Have you tried turning it off?",
    ]));

    $comment = $ticket->comments()->latest('id')->first();

    expect($comment->body)->toBe('Still broken.')
        ->and($comment->body)->not->toContain('turning it off');
});

it('strips the reference and forwarding prefixes from the subject', function (): void {
    $record = $this->processor->process($this->channel, inboundMail([
        'subject' => 'Re: Fwd: RE: [SUP-99] Coffee machine leaking',
    ]));

    $ticket = Ticket::query()->findOrFail($record->ticket_id);

    expect($ticket->subject)->toBe('Coffee machine leaking');
});

it('records a failure instead of losing the message', function (): void {
    $this->mock(TicketService::class)
        ->shouldReceive('create')
        ->andThrow(new RuntimeException('database is on fire'));

    $record = app(InboundMessageProcessor::class)->process($this->channel, inboundMail());

    expect($record->status)->toBe('failed')
        ->and($record->reason)->toContain('database is on fire');
});

it('recovers the ticket key from a message id it generated', function (): void {
    expect(TicketMailer::ticketKeyFromMessageId('<ticktz.SUP-1042.17.9f2ab3c1@ticktz.test>'))->toBe('SUP-1042')
        ->and(TicketMailer::ticketKeyFromMessageId('<CAF=abc123@mail.gmail.com>'))->toBeNull();
});
