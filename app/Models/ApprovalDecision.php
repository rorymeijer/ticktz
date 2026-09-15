<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person, one step, one answer.
 *
 * The row is written when the step opens, with `decision` still `pending`, so
 * "who was asked, and when" is recorded even for approvals nobody ever
 * answers. That is the half of the audit trail a decisions-only table loses.
 *
 * ### The token
 *
 * An approver can answer from their inbox without signing in, which is the
 * difference between an approval that takes an hour and one that takes a week.
 * That makes the token a bearer credential: whoever holds it can decide on
 * this person's behalf. So it is
 *
 * - **long and random** (32 bytes of `Str::random`, not a guessable id),
 * - **hashed at rest** — a leaked backup is not a pile of working approve
 *   links, and lookup is by hash exactly as a password reset works,
 * - **single-use** — cleared the moment the decision lands, so a forwarded
 *   mail cannot be replayed,
 * - **time-limited**, because an approve link that works forever is a key
 *   sitting in an inbox forever.
 *
 * And the link in the mail is a `GET` that only *shows* the decision page. The
 * decision itself is a `POST` from that page. A link that decided on `GET`
 * would be answered by the first mail scanner, link preview or prefetcher that
 * touched the message — approving things nobody read.
 */
class ApprovalDecision extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Somebody else settled the step first; this answer is no longer needed. */
    public const SKIPPED = 'skipped';

    /** @var array<int, string> */
    public const OUTCOMES = [self::APPROVED, self::REJECTED];

    protected $fillable = [
        'approval_request_id', 'approval_step_id', 'position', 'step_name', 'mode',
        'approver_id', 'decision', 'comment', 'source',
        'notified_at', 'decided_at', 'token_hash', 'token_expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'notified_at' => 'datetime',
            'decided_at' => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<ApprovalStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'approval_step_id');
    }

    public function isPending(): bool
    {
        return $this->decision === self::PENDING;
    }

    // -----------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------

    /**
     * Mint a fresh token and return the plaintext — the only moment it exists
     * in a readable form. The caller puts it in exactly one place: the mail to
     * this approver.
     */
    public function issueToken(?int $days = null): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'token_hash' => self::hash($token),
            'token_expires_at' => now()->addDays($days ?? (int) config('ticktz.approvals.token_days', 30)),
        ])->save();

        return $token;
    }

    /**
     * Retire the token. Called as the decision lands, so a mail that gets
     * forwarded afterwards carries a link that no longer does anything.
     */
    public function clearToken(): void
    {
        $this->forceFill(['token_hash' => null, 'token_expires_at' => null])->save();
    }

    public static function hash(string $token): string
    {
        // SHA-256 rather than bcrypt: the token is 48 random characters, so
        // there is no dictionary to stretch against, and the lookup has to be
        // an indexed equality match rather than a scan of every row.
        return hash('sha256', $token);
    }

    /**
     * The decision a token refers to, if the token is still good for anything.
     */
    public static function findByToken(string $token): ?self
    {
        if (trim($token) === '') {
            return null;
        }

        /** @var self|null $decision */
        $decision = self::query()
            ->where('token_hash', self::hash($token))
            ->where('decision', self::PENDING)
            ->where(fn ($query) => $query
                ->whereNull('token_expires_at')
                ->orWhere('token_expires_at', '>', now()))
            ->first();

        return $decision;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'step_name' => $this->step_name,
            'mode' => $this->mode,
            'decision' => $this->decision,
            'comment' => $this->comment,
            'source' => $this->source,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'notified_at' => $this->notified_at?->toIso8601String(),
            'approver' => $this->relationLoaded('approver') ? $this->approver?->toSummaryArray() : null,
        ];
    }
}
