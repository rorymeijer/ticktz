<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next ticket key for a prefix.
 *
 * The sequence row is selected FOR UPDATE inside the caller's transaction, so
 * two simultaneous submissions queue behind each other instead of both
 * claiming SUP-1042. `max(number) + 1` would have been simpler and wrong.
 */
class TicketNumberGenerator
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function prefix(): string
    {
        return mb_strtoupper($this->settings->string('tickets.key_prefix', 'SUP') ?: 'SUP');
    }

    /**
     * @return array{prefix: string, number: int, key: string}
     */
    public function next(?string $prefix = null): array
    {
        $prefix = mb_strtoupper($prefix ?? $this->prefix());

        $number = DB::transaction(function () use ($prefix): int {
            $row = DB::table('ticket_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // Start after any ticket that already exists with this prefix,
                // so re-introducing a prefix never collides with history.
                $start = (int) DB::table('tickets')->where('prefix', $prefix)->max('number') + 1;

                DB::table('ticket_sequences')->insert([
                    'prefix' => $prefix,
                    'next_number' => $start + 1,
                ]);

                return $start;
            }

            DB::table('ticket_sequences')
                ->where('prefix', $prefix)
                ->update(['next_number' => $row->next_number + 1]);

            return (int) $row->next_number;
        });

        return [
            'prefix' => $prefix,
            'number' => $number,
            'key' => "{$prefix}-{$number}",
        ];
    }
}
