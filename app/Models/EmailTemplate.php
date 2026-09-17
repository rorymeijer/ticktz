<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The text of one outgoing notification, per channel and language.
 *
 * Templates are plain text with `{{ placeholder }}` tokens rather than Blade:
 * they are edited by service desk managers, stored in the database, and must
 * never be able to execute code.
 *
 * @property string $key
 * @property string $subject
 * @property string $body
 */
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use Auditable, HasFactory;

    /**
     * The notifications Ticktz sends.
     */
    public const KEYS = [
        'ticket.created.requester',
        'ticket.created.agent',
        'ticket.replied.requester',
        'ticket.replied.agent',
        'ticket.assigned.agent',
        'ticket.resolved.requester',
    ];

    protected $fillable = ['key', 'email_channel_id', 'locale', 'subject', 'body', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<EmailChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(EmailChannel::class, 'email_channel_id');
    }

    /**
     * Find the best template for a notification: the channel's own override
     * in the reader's language, then the channel's default language, then the
     * instance-wide template, then null (which means the packaged default).
     */
    public static function resolve(string $key, ?EmailChannel $channel, string $locale): ?self
    {
        $fallback = (string) config('app.fallback_locale', 'en');

        $candidates = [
            [$channel?->getKey(), $locale],
            [$channel?->getKey(), $fallback],
            [null, $locale],
            [null, $fallback],
        ];

        foreach ($candidates as [$channelId, $candidateLocale]) {
            $template = static::query()
                ->where('key', $key)
                ->where('locale', $candidateLocale)
                ->where('is_active', true)
                ->when(
                    $channelId === null,
                    fn ($query) => $query->whereNull('email_channel_id'),
                    fn ($query) => $query->where('email_channel_id', $channelId),
                )
                ->first();

            if ($template) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Replace `{{ token }}` placeholders. Unknown tokens render as an empty
     * string rather than leaking the raw token into a customer's inbox.
     *
     * @param  array<string, string>  $values
     */
    public static function render(string $text, array $values): string
    {
        // Anything in double braces is a placeholder, known or not. A token
        // nobody recognises renders as nothing rather than being echoed into
        // a customer's inbox — and nothing here is ever evaluated.
        return preg_replace_callback(
            '/\{\{(.*?)\}\}/s',
            static fn (array $matches): string => $values[trim($matches[1])] ?? '',
            $text,
        ) ?? $text;
    }
}
