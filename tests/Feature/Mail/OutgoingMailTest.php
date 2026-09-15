<?php

declare(strict_types=1);

use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCommented;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketTransitioned;
use App\Mail\TicketNotification;
use App\Models\EmailChannel;
use App\Models\EmailTemplate;
use App\Models\Ticket;
use App\Services\Mail\TicketMailer;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();

    $this->channel = EmailChannel::factory()->create([
        'address' => 'servicedesk@ticktz.test',
        'from_name' => 'Ticktz Service Desk',
    ]);
});

/**
 * A ticket opened for someone is their record of it; the confirmation is the
 * thing they reply to.
 */
it('confirms a new ticket to the requester and alerts the assignee', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);
    $agent = makeAgent(['email' => 'sam@example.test']);

    $ticket = app(TicketService::class)->create([
        'subject' => 'Printer jams on double sided',
        'description' => 'Every second page.',
        'requester_id' => $requester->getKey(),
        'assignee_id' => $agent->getKey(),
        'email_channel_id' => $this->channel->getKey(),
    ], $requester);

    Mail::assertQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('nina@example.test')
        && str_contains($mail->renderedSubject, $ticket->key));

    Mail::assertQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('sam@example.test'));

    Mail::assertQueuedCount(2);
});

it('sends from the ticket channel address with a threadable message id', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);

    $ticket = app(TicketService::class)->create([
        'subject' => 'Badge does not open the side door',
        'requester_id' => $requester->getKey(),
        'email_channel_id' => $this->channel->getKey(),
    ], $requester);

    Mail::assertQueued(TicketNotification::class, function (TicketNotification $mail) use ($ticket): bool {
        $envelope = $mail->envelope();

        expect($envelope->from?->address)->toBe('servicedesk@ticktz.test')
            ->and(TicketMailer::ticketKeyFromMessageId($mail->messageId))->toBe($ticket->key);

        return true;
    });
});

/**
 * The single most damaging mail bug a service desk can have.
 */
it('never mails an internal note to the requester', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);
    $agent = makeAgent(['email' => 'sam@example.test']);
    $other = makeAgent(['email' => 'robin@example.test']);

    $ticket = Ticket::factory()->forRequester($requester)->assignedTo($agent)->create([
        'email_channel_id' => $this->channel->getKey(),
    ]);
    $ticket->watchers()->attach($other);

    Mail::fake();

    app(TicketService::class)->comment($ticket, 'Supplier says the part is on back order.', $other, internal: true);

    Mail::assertNotQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('nina@example.test'));
    Mail::assertQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('sam@example.test'));
});

it('mails a public reply to the requester', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);
    $agent = makeAgent(['email' => 'sam@example.test']);

    $ticket = Ticket::factory()->forRequester($requester)->assignedTo($agent)->create([
        'email_channel_id' => $this->channel->getKey(),
    ]);

    Mail::fake();

    app(TicketService::class)->comment($ticket, 'The part arrives Thursday.', $agent);

    Mail::assertQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('nina@example.test')
        && str_contains($mail->renderedBody, 'The part arrives Thursday.'));
});

it('does not tell an agent about their own action', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);
    $agent = makeAgent(['email' => 'sam@example.test']);

    $ticket = Ticket::factory()->forRequester($requester)->assignedTo($agent)->create();

    Mail::fake();

    app(TicketService::class)->comment($ticket, 'Looked into it.', $agent);

    Mail::assertNotQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('sam@example.test'));
});

it('notifies a new assignee but not an agent assigning to themselves', function (): void {
    $agent = makeAgent(['email' => 'sam@example.test']);
    $lead = makeAgent(['email' => 'robin@example.test']);
    $ticket = Ticket::factory()->create();

    Mail::fake();
    app(TicketService::class)->assign($ticket, $agent, $lead);
    Mail::assertQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('sam@example.test'));

    Mail::fake();
    app(TicketService::class)->assign($ticket, $lead, $lead);
    Mail::assertNothingQueued();
});

it('tells the requester when the ticket is resolved', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);
    $agent = makeAgent();
    $ticket = Ticket::factory()->forRequester($requester)->withStatus('open')->create();

    Mail::fake();

    app(TicketService::class)->transition($ticket, status('resolved'), $agent);

    Mail::assertQueued(TicketNotification::class, fn (TicketNotification $mail) => $mail->hasTo('nina@example.test')
        && str_contains(mb_strtolower($mail->renderedSubject), 'resolved'));
});

it('writes to each person in the language they read', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test', 'locale' => 'nl']);
    $ticket = Ticket::factory()->forRequester($requester)->create();

    [$subject, $body] = app(TicketMailer::class)->render(
        'ticket.created.requester',
        $ticket,
        $requester,
        null,
        $this->channel,
        'nl',
    );

    [, $englishBody] = app(TicketMailer::class)->render(
        'ticket.created.requester',
        $ticket,
        $requester,
        null,
        $this->channel,
        'en',
    );

    expect($body)->not->toBe($englishBody)
        ->and($body)->toStartWith('Beste ')
        ->and($englishBody)->toStartWith('Hello ')
        ->and($subject)->toContain($ticket->key);
});

it('prefers a channel override over the instance template over the packaged default', function (): void {
    $requester = makeRequester();
    $ticket = Ticket::factory()->forRequester($requester)->create();
    $mailer = app(TicketMailer::class);

    [$packaged] = $mailer->render('ticket.created.requester', $ticket, $requester, null, $this->channel, 'en');
    expect($packaged)->toContain($ticket->key);

    EmailTemplate::factory()->create([
        'key' => 'ticket.created.requester',
        'subject' => 'Instance: {{ ticket.key }}',
    ]);

    [$instanceWide] = $mailer->render('ticket.created.requester', $ticket, $requester, null, $this->channel, 'en');
    expect($instanceWide)->toBe("Instance: {$ticket->key}");

    EmailTemplate::factory()->create([
        'key' => 'ticket.created.requester',
        'email_channel_id' => $this->channel->getKey(),
        'subject' => 'Channel: {{ ticket.key }}',
    ]);

    [$channelSpecific] = $mailer->render('ticket.created.requester', $ticket, $requester, null, $this->channel, 'en');
    expect($channelSpecific)->toBe("Channel: {$ticket->key}");
});

/**
 * Templates are edited by service desk managers and stored in the database, so
 * they are text with tokens — never something that can execute.
 */
it('renders an unknown placeholder as nothing and does not evaluate blade', function (): void {
    $rendered = EmailTemplate::render(
        'Hi {{ requester.first_name }} {{ nope.at.all }} {{ 2 + 2 }}',
        ['requester.first_name' => 'Nina'],
    );

    expect($rendered)->toBe('Hi Nina  ');
});

it('sends one mail to someone who is both assignee and watcher', function (): void {
    $agent = makeAgent(['email' => 'sam@example.test']);
    $requester = makeRequester(['email' => 'nina@example.test']);

    $ticket = Ticket::factory()->forRequester($requester)->assignedTo($agent)->create();
    $ticket->watchers()->attach($agent);

    Mail::fake();

    app(TicketService::class)->comment($ticket, 'Any news?', $requester);

    Mail::assertQueuedCount(1);
});

it('skips people without an address or with a deactivated account', function (): void {
    $requester = makeRequester(['email' => 'nina@example.test']);
    $agent = makeAgent(['email' => 'sam@example.test', 'is_active' => false]);

    $ticket = Ticket::factory()->forRequester($requester)->assignedTo($agent)->create();

    Mail::fake();

    app(TicketService::class)->comment($ticket, 'Checking in.', $requester);

    Mail::assertNothingQueued();
});

it('registers one listener per ticket event so nothing goes out twice', function (): void {
    $events = [
        TicketCreated::class,
        TicketCommented::class,
        TicketAssigned::class,
        TicketTransitioned::class,
    ];

    foreach ($events as $event) {
        $listeners = collect(Event::getListeners($event));

        expect($listeners)->toHaveCount(1, "expected exactly one listener for {$event}");
    }
});

it('suppresses auto-responders on everything it sends', function (): void {
    $ticket = Ticket::factory()->create();

    $mail = new TicketNotification(
        ticket: $ticket,
        channel: $this->channel,
        renderedSubject: 'Subject',
        renderedBody: 'Body',
        messageId: 'ticktz.'.$ticket->key.'.0.abcd1234@ticktz.test',
    );

    $headers = $mail->headers();

    expect($headers->text)->toHaveKey('Auto-Submitted')
        ->and($headers->text['Auto-Submitted'])->toBe('auto-generated')
        ->and($headers->text)->toHaveKey('X-Auto-Response-Suppress')
        ->and($headers->messageId)->toBe('ticktz.'.$ticket->key.'.0.abcd1234@ticktz.test');
});

/**
 * A ticket must not fail to exist because a mail server is down.
 */
it('logs and swallows a mail failure instead of failing the action that caused it', function (): void {
    config(['queue.default' => 'sync']);

    $this->mock(TicketMailer::class, function ($mock): void {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('Connection refused'));
        $mock->shouldReceive('sendMany')->andThrow(new RuntimeException('Connection refused'));
    });

    $requester = makeRequester(['email' => 'nina@example.test']);

    $ticket = app(TicketService::class)->create([
        'subject' => 'The mail server is down',
        'requester_id' => $requester->getKey(),
    ], $requester);

    expect($ticket->exists)->toBeTrue()
        ->and(Ticket::query()->count())->toBe(1);
});
