<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BusinessCalendarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * When the clock runs.
 *
 * Every SLA duration in Ticktz is measured in *working* minutes, so this class
 * is the one place that knows what a working minute is. Two questions are
 * asked of it, and everything else is built from them:
 *
 *   addWorkingMinutes($from, 240)      -> when does a four-hour target expire?
 *   workingMinutesBetween($from, $to)  -> how much of the clock has run?
 *
 * Both walk the calendar day by day rather than doing arithmetic on a weekly
 * total, because holidays, split shifts and daylight saving all break the
 * arithmetic and none of them break the walk.
 *
 * @property string $timezone
 * @property array<string, array<int, array<int, string>>> $working_hours
 */
class BusinessCalendar extends Model
{
    /** @use HasFactory<BusinessCalendarFactory> */
    use Auditable, HasFactory;

    /**
     * Keys of `working_hours`, in the order a week runs. Carbon's dayOfWeek is
     * 0 for Sunday, so this array is indexed to match.
     *
     * @var array<int, string>
     */
    public const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * How far the walk will look for the next working minute before giving up.
     * A calendar with no working hours at all would otherwise loop forever;
     * this turns that misconfiguration into a due date a year out, which is
     * visibly wrong rather than a hung worker.
     */
    private const MAX_DAYS = 366;

    protected $fillable = [
        'name', 'slug', 'description', 'timezone', 'working_hours', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'working_hours' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /** @return HasMany<CalendarHoliday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class);
    }

    /** @return HasMany<SlaPolicy, $this> */
    public function policies(): HasMany
    {
        return $this->hasMany(SlaPolicy::class);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('id')->first();
    }

    /**
     * Round-the-clock cover: every minute of every day counts.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function alwaysOpen(): array
    {
        return array_fill_keys(self::DAYS, [['00:00', '24:00']]);
    }

    /**
     * A conventional office week.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function officeHours(string $start = '09:00', string $end = '17:00'): array
    {
        return [
            'mon' => [[$start, $end]],
            'tue' => [[$start, $end]],
            'wed' => [[$start, $end]],
            'thu' => [[$start, $end]],
            'fri' => [[$start, $end]],
            'sat' => [],
            'sun' => [],
        ];
    }

    // -----------------------------------------------------------------
    // The two questions
    // -----------------------------------------------------------------

    /**
     * The moment `$minutes` of working time after `$from` has elapsed.
     *
     * If `$from` falls outside working hours the clock starts at the next
     * opening, so a ticket filed at 22:00 on a Friday gets a Monday morning
     * start and a target measured from there.
     */
    public function addWorkingMinutes(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $remaining = max(0, $minutes);

        if ($remaining === 0) {
            return $this->appTime($this->nextOpening($cursor));
        }

        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            foreach ($this->intervalsOn($cursor) as [$opens, $closes]) {
                if ($closes->lessThanOrEqualTo($cursor)) {
                    continue;
                }

                $start = $cursor->greaterThan($opens) ? $cursor : $opens;
                $available = $start->diffInMinutes($closes);

                if ($available >= $remaining) {
                    return $this->appTime($start->addMinutes($remaining));
                }

                $remaining -= (int) $available;
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        // The calendar is unusable. Return something far enough out to be
        // obviously wrong rather than pretending the target was met.
        return $this->appTime($cursor);
    }

    /**
     * How many working minutes lie between two moments.
     */
    public function workingMinutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        $end = CarbonImmutable::instance($to)->setTimezone($this->timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $cursor = $start;
        $total = 0;

        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            if ($cursor->greaterThanOrEqualTo($end)) {
                break;
            }

            foreach ($this->intervalsOn($cursor) as [$opens, $closes]) {
                $windowStart = $cursor->greaterThan($opens) ? $cursor : $opens;
                $windowEnd = $end->lessThan($closes) ? $end : $closes;

                if ($windowEnd->greaterThan($windowStart)) {
                    $total += (int) $windowStart->diffInMinutes($windowEnd);
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $total;
    }

    /**
     * Is the clock running at this moment?
     */
    public function isOpenAt(CarbonInterface $moment): bool
    {
        $at = CarbonImmutable::instance($moment)->setTimezone($this->timezone);

        foreach ($this->intervalsOn($at) as [$opens, $closes]) {
            if ($at->greaterThanOrEqualTo($opens) && $at->lessThan($closes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first working moment at or after `$from`.
     */
    public function nextOpening(CarbonInterface $from): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->setTimezone($this->timezone);

        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            foreach ($this->intervalsOn($cursor) as [$opens, $closes]) {
                if ($cursor->lessThan($opens)) {
                    return $opens;
                }

                if ($cursor->lessThan($closes)) {
                    return $cursor;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $cursor;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Hand a moment back in the application's timezone.
     *
     * The absolute instant is the same whatever zone it carries, but Eloquent
     * is not: it writes a datetime using the instance's own wall-clock time
     * and reads it back assuming the application timezone. Returning a UTC
     * instance from a calendar that thinks in Amsterdam therefore stores a
     * due date two hours out — every hour of the summer, in the direction
     * that flatters the desk. Normalising here keeps the round trip honest.
     */
    private function appTime(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone(config('app.timezone') ?: 'UTC');
    }

    /**
     * The working intervals of the day `$moment` falls on, as absolute
     * moments in the calendar's timezone, in order. A holiday has none.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function intervalsOn(CarbonImmutable $moment): array
    {
        $day = $moment->startOfDay();

        if ($this->isHoliday($day)) {
            return [];
        }

        $definition = $this->working_hours[self::DAYS[$day->dayOfWeek]] ?? [];
        $intervals = [];

        foreach ($definition as $window) {
            if (! is_array($window) || count($window) < 2) {
                continue;
            }

            [$opens, $closes] = [$this->at($day, (string) $window[0]), $this->at($day, (string) $window[1])];

            if ($closes->greaterThan($opens)) {
                $intervals[] = [$opens, $closes];
            }
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $intervals;
    }

    /**
     * "09:00" on a given day. "24:00" means midnight at the end of it, which
     * is how a round-the-clock calendar is written without an interval that
     * spills into tomorrow.
     *
     * `setTime` rather than `addHours`, because on the day the clocks go
     * forward those are not the same moment: adding nine hours to midnight
     * gives 10:00, and the desk still opens at nine.
     */
    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        if ($hours >= 24) {
            return $day->addDay()->startOfDay();
        }

        return $day->setTime($hours, $minutes);
    }

    private function isHoliday(CarbonImmutable $day): bool
    {
        return $this->holidayIndex()->contains($day->format('Y-m-d'))
            || $this->holidayIndex()->contains($day->format('m-d'));
    }

    /**
     * Holiday dates, loaded once per calendar instance. Exact dates are keyed
     * `Y-m-d`, recurring ones `m-d`, so a single lookup answers both.
     *
     * @return Collection<int, string>
     */
    private function holidayIndex(): Collection
    {
        $holidays = $this->relationLoaded('holidays')
            ? $this->holidays
            : $this->holidays()->get();

        return $this->holidayIndex ??= $holidays
            ->map(fn (CalendarHoliday $holiday): string => $holiday->is_recurring
                ? $holiday->date->format('m-d')
                : $holiday->date->format('Y-m-d'))
            ->values();
    }

    /** @var Collection<int, string>|null */
    private ?Collection $holidayIndex = null;
}
