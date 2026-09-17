<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Database\Factories\CalendarHolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day the desk is shut.
 *
 * `is_recurring` means the same day every year — New Year's Day, Christmas —
 * and the year stored in `date` is then ignored. Easter and the days that hang
 * off it move, so they get a row per year.
 *
 * @property CarbonImmutable $date
 * @property bool $is_recurring
 */
class CalendarHoliday extends Model
{
    /** @use HasFactory<CalendarHolidayFactory> */
    use Auditable, HasFactory;

    protected $fillable = ['business_calendar_id', 'name', 'date', 'is_recurring'];

    protected function casts(): array
    {
        return [
            // The format matters: without it the value round-trips through the
            // database with a time component, and a lookup by 'Y-m-d' — which
            // is what both the seeder and the admin form do — silently matches
            // nothing and inserts a duplicate against the unique index.
            'date' => 'immutable_date:Y-m-d',
            'is_recurring' => 'boolean',
        ];
    }

    /** @return BelongsTo<BusinessCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'date' => $this->date->format('Y-m-d'),
            'is_recurring' => $this->is_recurring,
        ];
    }
}
