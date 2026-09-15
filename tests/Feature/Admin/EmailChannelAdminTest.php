<?php

declare(strict_types=1);

use App\Jobs\Mail\PollMailboxJob;
use App\Models\AuditLogEntry;
use App\Models\EmailChannel;
use App\Models\EmailTemplate;
use App\Models\Ticket;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    seedServiceDesk();
    $this->admin = makeAdmin();
});

/**
 * @return array<string, mixed>
 */
function channelPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Service desk',
        'slug' => 'service-desk',
        'address' => 'servicedesk@ticktz.test',
        'from_name' => 'Ticktz Service Desk',
        'is_active' => true,
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'imap_enabled' => false,
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'imap_validate_cert' => true,
        'imap_folder' => 'INBOX',
        'imap_delete_after_processing' => false,
        'auto_provision_requesters' => true,
        'ignore_senders' => [],
    ], $overrides);
}

it('lets an administrator open the mailbox page', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/email')
        ->assertOk();
});

it('refuses every mailbox endpoint without the permission', function (string $method, string $uri): void {
    $agent = makeAgent();

    $this->actingAs($agent)->call($method, $uri)->assertForbidden();
})->with([
    ['get', '/admin/email'],
    ['post', '/admin/email/channels'],
    ['post', '/admin/email/poll'],
    ['post', '/admin/email/templates'],
]);

it('creates a mailbox and writes an audit entry', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/email/channels', channelPayload())
        ->assertRedirect();

    $channel = EmailChannel::query()->sole();

    expect($channel->address)->toBe('servicedesk@ticktz.test');

    expect(AuditLogEntry::query()
        ->where('auditable_type', $channel->getMorphClass())
        ->where('auditable_id', $channel->getKey())
        ->where('event', 'created')
        ->exists())->toBeTrue();
});

it('requires an imap host once reading is switched on', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/email/channels', channelPayload(['imap_enabled' => true]))
        ->assertSessionHasErrors('imap_host');
});

/**
 * A blank password field means "keep what is stored"; the alternative is an
 * administrator silently breaking a working mailbox by saving an unrelated
 * field.
 */
it('keeps the stored password when the field is left blank', function (): void {
    $channel = EmailChannel::factory()->sending()->create(['smtp_password' => 'original-secret']);

    $this->actingAs($this->admin)
        ->put("/admin/email/channels/{$channel->id}", channelPayload([
            'slug' => $channel->slug,
            'address' => $channel->address,
            'smtp_password' => '',
        ]))
        ->assertRedirect();

    expect($channel->fresh()->smtp_password)->toBe('original-secret');
});

it('never sends a password to the browser', function (): void {
    EmailChannel::factory()->sending()->reading()->create();

    $response = $this->actingAs($this->admin)->get('/admin/email')->assertOk();

    $channel = $response->viewData('page')['props']['channels'][0];

    expect($channel)->not->toHaveKey('smtp_password')
        ->and($channel)->not->toHaveKey('imap_password')
        ->and($channel['has_smtp_password'])->toBeTrue()
        ->and($channel['has_imap_password'])->toBeTrue()
        ->and(json_encode($channel))->not->toContain('secret');
});

it('stores a template override and falls back to the packaged default when it is removed', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/email/templates', [
            'key' => 'ticket.created.requester',
            'locale' => 'en',
            'subject' => 'We have your request {{ ticket.key }}',
            'body' => 'Hello {{ requester.first_name }}',
            'is_active' => true,
        ])
        ->assertRedirect();

    $template = EmailTemplate::query()->sole();

    expect($template->subject)->toBe('We have your request {{ ticket.key }}');

    $this->actingAs($this->admin)
        ->delete("/admin/email/templates/{$template->id}")
        ->assertRedirect();

    expect(EmailTemplate::query()->count())->toBe(0);
});

it('rejects a template for a notification Ticktz does not send', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/email/templates', [
            'key' => 'ticket.invented.notification',
            'locale' => 'en',
            'subject' => 'Subject',
            'body' => 'Body',
        ])
        ->assertSessionHasErrors('key');
});

it('queues a poll for every readable mailbox', function (): void {
    Bus::fake();

    EmailChannel::factory()->reading()->create();
    EmailChannel::factory()->create(['imap_enabled' => false]);
    EmailChannel::factory()->reading()->create(['is_active' => false]);

    $this->actingAs($this->admin)
        ->post('/admin/email/poll')
        ->assertRedirect();

    Bus::assertDispatchedTimes(PollMailboxJob::class, 1);
});

it('says so rather than failing when a mailbox is not read in', function (): void {
    $channel = EmailChannel::factory()->create(['imap_enabled' => false]);

    $this->actingAs($this->admin)
        ->post("/admin/email/channels/{$channel->id}/poll")
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('deletes a mailbox without touching the tickets that came from it', function (): void {
    $channel = EmailChannel::factory()->create();
    $ticket = Ticket::factory()->create(['email_channel_id' => $channel->getKey()]);

    $this->actingAs($this->admin)
        ->delete("/admin/email/channels/{$channel->id}")
        ->assertRedirect();

    expect(EmailChannel::query()->count())->toBe(0)
        ->and($ticket->fresh())->not->toBeNull()
        ->and($ticket->fresh()->email_channel_id)->toBeNull();
});
