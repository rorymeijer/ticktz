<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message the poller has seen.
 *
 * The row is written *before* a ticket is created, keyed on the channel and a
 * hash of the RFC 5322 Message-ID. That unique index is what makes inbound
 * processing idempotent: a message delivered twice — a retried poll, a
 * duplicate on the server, a job that crashed halfway — finds the row on the
 * second pass and stops.
 *
 * @property string $message_id
 * @property array<int, string>|null $references
 */
class InboundMessage extends Model
{
    protected $fillable = [
        'email_channel_id', 'message_id', 'message_hash', 'in_reply_to', 'references',
        'from_address', 'from_name', 'to_addresses', 'subject', 'body_text', 'body_html',
        'status', 'reason', 'ticket_id', 'comment_id', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'references' => 'array',
            'to_addresses' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmailChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(EmailChannel::class, 'email_channel_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public static function hashFor(string $messageId): string
    {
        return hash('sha256', mb_strtolower(trim($messageId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'from' => trim(($this->from_name ?? '').' <'.$this->from_address.'>'),
            'from_address' => $this->from_address,
            'subject' => $this->subject,
            'status' => $this->status,
            'reason' => $this->reason,
            'ticket_key' => $this->relationLoaded('ticket') ? $this->ticket?->key : null,
            'received_at' => $this->received_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
