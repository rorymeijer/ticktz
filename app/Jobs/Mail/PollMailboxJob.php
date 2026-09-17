<?php

declare(strict_types=1);

namespace App\Jobs\Mail;

use App\Models\EmailChannel;
use App\Services\Mail\MailboxPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls one mailbox.
 *
 * `ShouldBeUnique` keyed on the channel means a slow mailbox cannot stack up
 * overlapping polls when the scheduler fires again before the last one
 * finished — the second dispatch is simply dropped.
 */
class PollMailboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * How long the uniqueness lock survives a worker that dies mid-poll.
     */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $channelId)
    {
        $this->onQueue(config('ticktz.queues.mail'));
    }

    public function uniqueId(): string
    {
        return 'poll-mailbox-'.$this->channelId;
    }

    public function handle(MailboxPoller $poller): void
    {
        $channel = EmailChannel::query()->find($this->channelId);

        if (! $channel || ! $channel->is_active || ! $channel->imap_enabled) {
            return;
        }

        try {
            $stats = $poller->poll($channel);

            Log::info('Polled mailbox [{channel}]: {summary}', [
                'channel' => $channel->slug,
                'summary' => json_encode($stats),
            ]);
        } catch (Throwable $exception) {
            $channel->forceFill([
                'last_polled_at' => now(),
                'last_poll_result' => mb_substr('Failed: '.$exception->getMessage(), 0, 255),
            ])->save();

            throw $exception;
        }
    }
}
