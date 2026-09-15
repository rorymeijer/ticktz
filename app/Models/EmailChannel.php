<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\EmailChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One mailbox: the address replies come from, and the inbox tickets arrive in.
 *
 * Passwords are encrypted at rest and never leave the server — `toAdminArray()`
 * reduces them to a boolean.
 *
 * @property array<int, string>|null $ignore_senders
 */
class EmailChannel extends Model
{
    /** @use HasFactory<EmailChannelFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'name', 'slug', 'address', 'from_name', 'reply_to', 'is_active',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'imap_enabled', 'imap_host', 'imap_port', 'imap_encryption', 'imap_validate_cert',
        'imap_username', 'imap_password', 'imap_folder', 'imap_processed_folder',
        'imap_delete_after_processing',
        'queue_id', 'team_id', 'request_type_id', 'priority_id',
        'auto_provision_requesters', 'ignore_senders',
    ];

    protected $hidden = ['smtp_password', 'imap_password'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'imap_enabled' => 'boolean',
            'imap_validate_cert' => 'boolean',
            'imap_delete_after_processing' => 'boolean',
            'auto_provision_requesters' => 'boolean',
            'ignore_senders' => 'array',
            'smtp_password' => 'encrypted',
            'imap_password' => 'encrypted',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * Senders whose mail never becomes a ticket. Bounce handlers and
     * auto-responders would otherwise create an endless loop of tickets
     * answering tickets.
     *
     * @var array<int, string>
     */
    public const DEFAULT_IGNORED = [
        'mailer-daemon@*',
        'postmaster@*',
        'no-reply@*',
        'noreply@*',
        'donotreply@*',
        'bounce*@*',
    ];

    /** @return BelongsTo<Queue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<RequestType, $this> */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /** @return HasMany<InboundMessage, $this> */
    public function inboundMessages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }

    /** @return HasMany<EmailTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    /**
     * Should mail from this address be dropped without creating a ticket?
     *
     * Patterns support a trailing or leading `*`, which covers the shapes that
     *
     * actually occur (`bounce*@*`, `*@example.org`).
     */
    public function ignoresSender(string $address): bool
    {
        $address = mb_strtolower(trim($address));

        if ($address === '' || $address === mb_strtolower($this->address)) {
            // Mail from the mailbox to itself is a loop, always.
            return true;
        }

        foreach ([...self::DEFAULT_IGNORED, ...($this->ignore_senders ?? [])] as $pattern) {
            if (fnmatch(mb_strtolower(trim($pattern)), $address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mailer configuration for this channel, or null to use the application
     * default (which is how a small instance is normally set up).
     *
     * @return array<string, mixed>|null
     */
    public function mailerConfig(): ?array
    {
        if (blank($this->smtp_host)) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'host' => $this->smtp_host,
            'port' => $this->smtp_port,
            'encryption' => $this->smtp_encryption === 'none' ? null : $this->smtp_encryption,
            'username' => $this->smtp_username,
            'password' => $this->smtp_password,
            'timeout' => 15,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function imapConfig(): array
    {
        return [
            'host' => $this->imap_host,
            'port' => $this->imap_port,
            'protocol' => 'imap',
            'encryption' => $this->imap_encryption === 'none' ? false : $this->imap_encryption,
            'validate_cert' => $this->imap_validate_cert,
            'username' => $this->imap_username ?: $this->address,
            'password' => $this->imap_password,
            'authentication' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'address' => $this->address,
            'from_name' => $this->from_name,
            'reply_to' => $this->reply_to,
            'is_active' => $this->is_active,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption,
            'smtp_username' => $this->smtp_username,
            'has_smtp_password' => filled($this->getRawOriginal('smtp_password')),
            'imap_enabled' => $this->imap_enabled,
            'imap_host' => $this->imap_host,
            'imap_port' => $this->imap_port,
            'imap_encryption' => $this->imap_encryption,
            'imap_validate_cert' => $this->imap_validate_cert,
            'imap_username' => $this->imap_username,
            'has_imap_password' => filled($this->getRawOriginal('imap_password')),
            'imap_folder' => $this->imap_folder,
            'imap_processed_folder' => $this->imap_processed_folder,
            'imap_delete_after_processing' => $this->imap_delete_after_processing,
            'queue_id' => $this->queue_id,
            'team_id' => $this->team_id,
            'request_type_id' => $this->request_type_id,
            'priority_id' => $this->priority_id,
            'auto_provision_requesters' => $this->auto_provision_requesters,
            'ignore_senders' => $this->ignore_senders ?? [],
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'last_poll_result' => $this->last_poll_result,
        ];
    }
}
