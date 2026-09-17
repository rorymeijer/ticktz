<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt at moving this instance to another version.
 *
 * Written by a web request, acted on by the worker. That split is the whole
 * security design: a web-facing PHP process must never be able to write the
 * application's own code, because a service desk accepts uploads from anybody
 * with an e-mail address and every file-write bug would become a way to run
 * code. What a request can do is create one of these, naming a version that
 * has to be a published release. What replaces the code is a different process.
 */
class Upgrade extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const INSTALLED = 'installed';

    public const FAILED = 'failed';

    protected $fillable = ['from_version', 'to_version', 'status', 'requested_by'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::INSTALLED, self::FAILED], true);
    }

    /**
     * Add a line to the account of what happened.
     *
     * Saved immediately rather than buffered: the screen reads this row while
     * the upgrade runs, and a log that only appears once the process is done
     * is of no use during the part where somebody is watching.
     */
    public function note(string $line): void
    {
        $this->forceFill([
            'log' => trim(($this->log ?? '')."\n".now()->toTimeString().'  '.$line),
        ])->save();
    }

    /**
     * How long an upgrade may sit unclaimed before the screen stops calling it
     * queued and starts calling it stuck.
     *
     * The honest answer to "is a worker running": not a guess made up front,
     * but the observation that nothing has picked this up.
     */
    public function looksAbandoned(): bool
    {
        return $this->status === self::QUEUED
            && $this->created_at?->lt(now()->subMinutes(5)) === true;
    }
}
