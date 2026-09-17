<?php

declare(strict_types=1);

use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use Carbon\CarbonImmutable;

/**
 * The arithmetic every SLA in the product rests on. If these are wrong, every
 * due date in the instance is wrong, so they are spelled out rather than
 * generated.
 *
 * All the fixed dates below are real: 2025-06-02 is a Monday, 2025-06-07 a
 * Saturday, 2025-12-25 a Thursday.
 */
function officeCalendar(string $start = '09:00', string $end = '17:00'): BusinessCalendar
{
    return BusinessCalendar::factory()->officeHours($start, $end)->create(['timezone' => 'Europe/Amsterdam']);
}

function amsterdam(string $datetime): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, 'Europe/Amsterdam');
}

it('adds minutes inside a single working day', function (): void {
    $calendar = officeCalendar();

    $due = $calendar->addWorkingMinutes(amsterdam('2025-06-02 10:00'), 120);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-06-02 12:00');
});

it('carries the remainder into the next working day', function (): void {
    $calendar = officeCalendar();

    // 16:00 Monday + 4 working hours: one hour today, three tomorrow morning.
    $due = $calendar->addWorkingMinutes(amsterdam('2025-06-02 16:00'), 240);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-06-03 12:00');
});

it('starts the clock at the next opening when the ticket arrives out of hours', function (): void {
    $calendar = officeCalendar();

    // Filed at 22:00 on a Monday: the clock starts at 09:00 Tuesday.
    $due = $calendar->addWorkingMinutes(amsterdam('2025-06-02 22:00'), 60);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-06-03 10:00');
});

it('skips the weekend', function (): void {
    $calendar = officeCalendar();

    // Friday 16:00 + 4 working hours lands on Monday morning, not Saturday.
    $due = $calendar->addWorkingMinutes(amsterdam('2025-06-06 16:00'), 240);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-06-09 12:00');
});

it('skips a holiday', function (): void {
    $calendar = officeCalendar();
    CalendarHoliday::factory()->for($calendar, 'calendar')->create(['date' => '2025-06-03']);

    // Monday 16:00 + 4 hours would be Tuesday noon, but Tuesday is shut.
    $due = $calendar->addWorkingMinutes(amsterdam('2025-06-02 16:00'), 240);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-06-04 12:00');
});

it('skips a recurring holiday whatever year it is stored under', function (): void {
    $calendar = officeCalendar();
    CalendarHoliday::factory()->recurring()->for($calendar, 'calendar')->create([
        'name' => 'Christmas Day',
        'date' => '2000-12-25',
    ]);

    // 2025-12-24 is a Wednesday; Christmas Day is Thursday.
    $due = $calendar->addWorkingMinutes(amsterdam('2025-12-24 16:00'), 240);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-12-26 12:00');
});

it('honours a lunch break', function (): void {
    $calendar = BusinessCalendar::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'working_hours' => [
            'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
            'tue' => [['09:00', '12:00'], ['13:00', '17:00']],
        ] + array_fill_keys(['wed', 'thu', 'fri', 'sat', 'sun'], []),
    ]);

    // 11:00 + 2 working hours: one hour to noon, then the hour after lunch.
    $due = $calendar->addWorkingMinutes(amsterdam('2025-06-02 11:00'), 120);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-06-02 14:00');
});

it('treats an always-open calendar as wall-clock time', function (): void {
    $calendar = BusinessCalendar::factory()->alwaysOpen()->create(['timezone' => 'UTC']);

    $due = $calendar->addWorkingMinutes(CarbonImmutable::parse('2025-06-07 22:00', 'UTC'), 240);

    expect($due->utc()->format('Y-m-d H:i'))->toBe('2025-06-08 02:00');
});

it('counts the working minutes between two moments', function (): void {
    $calendar = officeCalendar();

    // Monday 16:00 to Tuesday 10:00: one hour Monday, one hour Tuesday.
    $minutes = $calendar->workingMinutesBetween(
        amsterdam('2025-06-02 16:00'),
        amsterdam('2025-06-03 10:00'),
    );

    expect($minutes)->toBe(120);
});

it('counts nothing across a closed weekend', function (): void {
    $calendar = officeCalendar();

    $minutes = $calendar->workingMinutesBetween(
        amsterdam('2025-06-07 09:00'),
        amsterdam('2025-06-08 17:00'),
    );

    expect($minutes)->toBe(0);
});

it('counts a full working week', function (): void {
    $calendar = officeCalendar();

    $minutes = $calendar->workingMinutesBetween(
        amsterdam('2025-06-02 09:00'),
        amsterdam('2025-06-06 17:00'),
    );

    expect($minutes)->toBe(5 * 8 * 60);
});

it('returns zero when the end is before the start', function (): void {
    $calendar = officeCalendar();

    expect($calendar->workingMinutesBetween(amsterdam('2025-06-03 10:00'), amsterdam('2025-06-02 10:00')))->toBe(0);
});

it('knows whether it is open', function (): void {
    $calendar = officeCalendar();

    expect($calendar->isOpenAt(amsterdam('2025-06-02 10:00')))->toBeTrue()
        ->and($calendar->isOpenAt(amsterdam('2025-06-02 08:59')))->toBeFalse()
        ->and($calendar->isOpenAt(amsterdam('2025-06-02 17:00')))->toBeFalse()
        ->and($calendar->isOpenAt(amsterdam('2025-06-07 10:00')))->toBeFalse();
});

/**
 * Adding nine hours to midnight on the day the clocks go forward gives 10:00.
 * The desk still opens at nine.
 */
it('opens at the same wall-clock time across a daylight saving change', function (): void {
    $calendar = officeCalendar();

    // 2025-03-30 is the Sunday the Netherlands moves to summer time; the
    // Monday after is a normal working day.
    $due = $calendar->addWorkingMinutes(amsterdam('2025-03-30 12:00'), 60);

    expect($due->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2025-03-31 10:00');
});

it('measures a day either side of a daylight saving change as eight hours', function (): void {
    $calendar = officeCalendar();

    $before = $calendar->workingMinutesBetween(amsterdam('2025-03-28 09:00'), amsterdam('2025-03-28 17:00'));
    $after = $calendar->workingMinutesBetween(amsterdam('2025-03-31 09:00'), amsterdam('2025-03-31 17:00'));

    expect($before)->toBe(480)->and($after)->toBe(480);
});

/**
 * A calendar with no working hours at all is a misconfiguration. It must not
 * hang the worker that walks it.
 */
it('gives up rather than looping forever on a calendar that never opens', function (): void {
    $calendar = BusinessCalendar::factory()->create([
        'working_hours' => array_fill_keys(BusinessCalendar::DAYS, []),
    ]);

    $due = $calendar->addWorkingMinutes(amsterdam('2025-06-02 10:00'), 60);

    expect($due->greaterThan(amsterdam('2026-05-01 00:00')))->toBeTrue();
});

it('reads working hours in its own timezone', function (): void {
    $amsterdam = officeCalendar();
    $sydney = BusinessCalendar::factory()->officeHours()->create(['timezone' => 'Australia/Sydney']);

    $moment = CarbonImmutable::parse('2025-06-02 08:00', 'UTC');

    // 08:00 UTC is 10:00 in Amsterdam and 18:00 in Sydney.
    expect($amsterdam->isOpenAt($moment))->toBeTrue()
        ->and($sydney->isOpenAt($moment))->toBeFalse();
});
