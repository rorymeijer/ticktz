<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use App\Models\Organization;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\RequestType;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Policies, goals, calendars and holidays — everything an administrator sets
 * before the engine has anything to measure.
 *
 * Deleting is guarded rather than cascaded in two places: a calendar in use by
 * a policy cannot be removed, and neither can the last policy standing. Both
 * would otherwise leave an instance measuring nothing, silently.
 */
class SlaPolicyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('sla.manage');

        return Inertia::render('Admin/Sla/Index', [
            'policies' => SlaPolicy::query()
                ->with(['goals', 'calendar'])
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (SlaPolicy $policy) => $policy->toAdminArray())
                ->all(),
            'calendars' => BusinessCalendar::query()
                ->with('holidays')
                ->withCount('policies')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (BusinessCalendar $calendar) => [
                    'id' => $calendar->id,
                    'name' => $calendar->name,
                    'slug' => $calendar->slug,
                    'description' => $calendar->description,
                    'timezone' => $calendar->timezone,
                    'working_hours' => $calendar->working_hours,
                    'is_default' => $calendar->is_default,
                    'policies_count' => $calendar->policies_count,
                    'holidays' => $calendar->holidays
                        ->sortBy('date')
                        ->map(fn (CalendarHoliday $holiday) => $holiday->toAdminArray())
                        ->values()
                        ->all(),
                ])
                ->all(),
            'options' => [
                'metrics' => SlaGoal::METRICS,
                'actions' => SlaGoal::ACTIONS,
                'notify_targets' => SlaGoal::NOTIFY_TARGETS,
                'days' => BusinessCalendar::DAYS,
                'timezones' => $this->timezones(),
                'priorities' => Priority::query()->orderBy('level')->get()
                    ->map(fn (Priority $priority) => $priority->toSummaryArray())->all(),
                'queues' => Queue::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
                'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
                'request_types' => RequestType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
                'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Policies
    // -----------------------------------------------------------------

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('sla.manage');

        $policy = SlaPolicy::query()->create($this->validatePolicy($request, null));
        $this->ensureSingleDefault($policy);

        $this->audit->created($policy, "Created SLA policy {$policy->name}");

        return back()->with('success', __('sla.policies.created', ['name' => $policy->name]));
    }

    public function update(Request $request, SlaPolicy $policy): RedirectResponse
    {
        $this->authorize('sla.manage');

        $policy->fill($this->validatePolicy($request, $policy))->save();
        $this->ensureSingleDefault($policy);

        $this->audit->updated($policy, "Updated SLA policy {$policy->name}");

        return back()->with('success', __('sla.policies.updated', ['name' => $policy->name]));
    }

    public function destroy(SlaPolicy $policy): RedirectResponse
    {
        $this->authorize('sla.manage');

        // An instance with no policy measures nothing, and does so quietly.
        abort_if(SlaPolicy::query()->count() <= 1, 422, __('sla.policies.last_one'));

        $this->audit->deleted($policy, "Deleted SLA policy {$policy->name}");
        $policy->delete();

        return back()->with('success', __('sla.policies.deleted'));
    }

    // -----------------------------------------------------------------
    // Goals
    // -----------------------------------------------------------------

    public function storeGoal(Request $request, SlaPolicy $policy): RedirectResponse
    {
        $this->authorize('sla.manage');

        $data = $this->validateGoal($request);

        $goal = SlaGoal::query()->updateOrCreate(
            [
                'sla_policy_id' => $policy->getKey(),
                'metric' => $data['metric'],
                'priority_id' => $data['priority_id'] ?? null,
                'request_type_id' => $data['request_type_id'] ?? null,
            ],
            $data,
        );

        $this->audit->updated($goal, "Saved {$data['metric']} goal on {$policy->name}");

        return back()->with('success', __('sla.goals.saved'));
    }

    public function updateGoal(Request $request, SlaGoal $goal): RedirectResponse
    {
        $this->authorize('sla.manage');

        $goal->fill($this->validateGoal($request))->save();

        $this->audit->updated($goal, "Updated {$goal->metric} goal");

        return back()->with('success', __('sla.goals.saved'));
    }

    public function destroyGoal(SlaGoal $goal): RedirectResponse
    {
        $this->authorize('sla.manage');

        $this->audit->deleted($goal, "Deleted {$goal->metric} goal");
        $goal->delete();

        return back()->with('success', __('sla.goals.deleted'));
    }

    // -----------------------------------------------------------------
    // Calendars
    // -----------------------------------------------------------------

    public function storeCalendar(Request $request): RedirectResponse
    {
        $this->authorize('sla.manage');

        $calendar = BusinessCalendar::query()->create($this->validateCalendar($request, null));
        $this->ensureSingleDefaultCalendar($calendar);

        $this->audit->created($calendar, "Created business calendar {$calendar->name}");

        return back()->with('success', __('sla.calendars.created', ['name' => $calendar->name]));
    }

    public function updateCalendar(Request $request, BusinessCalendar $calendar): RedirectResponse
    {
        $this->authorize('sla.manage');

        $calendar->fill($this->validateCalendar($request, $calendar))->save();
        $this->ensureSingleDefaultCalendar($calendar);

        $this->audit->updated($calendar, "Updated business calendar {$calendar->name}");

        return back()->with('success', __('sla.calendars.updated', ['name' => $calendar->name]));
    }

    public function destroyCalendar(BusinessCalendar $calendar): RedirectResponse
    {
        $this->authorize('sla.manage');

        // Deleting a calendar a policy depends on would leave that policy
        // unable to work out any due date at all.
        abort_if($calendar->policies()->exists(), 422, __('sla.calendars.in_use'));

        $this->audit->deleted($calendar, "Deleted business calendar {$calendar->name}");
        $calendar->delete();

        return back()->with('success', __('sla.calendars.deleted'));
    }

    // -----------------------------------------------------------------
    // Holidays
    // -----------------------------------------------------------------

    public function storeHoliday(Request $request, BusinessCalendar $calendar): RedirectResponse
    {
        $this->authorize('sla.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'is_recurring' => ['boolean'],
        ]);

        $holiday = CalendarHoliday::query()->updateOrCreate(
            [
                'business_calendar_id' => $calendar->getKey(),
                'name' => $data['name'],
                'date' => $data['date'],
            ],
            ['is_recurring' => $data['is_recurring'] ?? false],
        );

        $this->audit->updated($holiday, "Added {$holiday->name} to {$calendar->name}");

        return back()->with('success', __('sla.holidays.saved'));
    }

    public function destroyHoliday(CalendarHoliday $holiday): RedirectResponse
    {
        $this->authorize('sla.manage');

        $this->audit->deleted($holiday, "Removed holiday {$holiday->name}");
        $holiday->delete();

        return back()->with('success', __('sla.holidays.deleted'));
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validatePolicy(Request $request, ?SlaPolicy $policy): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('sla_policies', 'slug')->ignore($policy?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'business_calendar_id' => ['required', 'integer', Rule::exists('business_calendars', 'id')],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:65535'],
            'conditions' => ['array'],
            'conditions.*' => ['array'],
            'conditions.*.*' => ['integer'],
        ]);

        // Only the conditions the engine knows how to evaluate are stored: a
        // key it would ignore must not look configured.
        $validated['conditions'] = array_filter(array_intersect_key(
            $validated['conditions'] ?? [],
            SlaPolicy::CONDITIONS,
        ));

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGoal(Request $request): array
    {
        $validated = $request->validate([
            'metric' => ['required', Rule::in(SlaGoal::METRICS)],
            'priority_id' => ['nullable', 'integer', Rule::exists('priorities', 'id')],
            'request_type_id' => ['nullable', 'integer', Rule::exists('request_types', 'id')],
            // A little over a working year, which is far past useful and well
            // inside what the calendar walk will look at.
            'target_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'is_active' => ['boolean'],
            'escalations' => ['array', 'max:10'],
            'escalations.*.at' => ['required', 'integer', 'min:1', 'max:500'],
            'escalations.*.actions' => ['required', 'array', 'min:1', 'max:5'],
            'escalations.*.actions.*.type' => ['required', Rule::in(SlaGoal::ACTIONS)],
            'escalations.*.actions.*.to' => ['nullable', Rule::in(SlaGoal::NOTIFY_TARGETS)],
            'escalations.*.actions.*.team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
        ]);

        $validated['escalations'] = $validated['escalations'] ?? [];

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCalendar(Request $request, ?BusinessCalendar $calendar): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('business_calendars', 'slug')->ignore($calendar?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
            'is_default' => ['boolean'],
            'working_hours' => ['required', 'array'],
            'working_hours.*' => ['array', 'max:4'],
            'working_hours.*.*' => ['array', 'size:2'],
            // 24:00 is how a day that runs to midnight is written.
            'working_hours.*.*.*' => ['required', 'string', 'regex:/^(?:[01]\d|2[0-4]):[0-5]\d$/'],
        ]);

        $validated['working_hours'] = array_intersect_key(
            $validated['working_hours'],
            array_flip(BusinessCalendar::DAYS),
        );

        return $validated;
    }

    /**
     * Exactly one policy is the default. Promoting one demotes the rest.
     */
    private function ensureSingleDefault(SlaPolicy $policy): void
    {
        if (! $policy->is_default) {
            return;
        }

        DB::transaction(function () use ($policy): void {
            SlaPolicy::query()->whereKeyNot($policy->getKey())->update(['is_default' => false]);
        });
    }

    private function ensureSingleDefaultCalendar(BusinessCalendar $calendar): void
    {
        if (! $calendar->is_default) {
            return;
        }

        DB::transaction(function () use ($calendar): void {
            BusinessCalendar::query()->whereKeyNot($calendar->getKey())->update(['is_default' => false]);
        });
    }

    /**
     * @return array<int, string>
     */
    private function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
}
