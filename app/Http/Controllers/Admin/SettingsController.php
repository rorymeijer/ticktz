<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Form field name => settings key.
     *
     * The form uses flat names because Inertia's `useForm` treats a dot in a
     * field name as a nested path, which would silently turn
     * `portal.title` into `{portal: {title: ...}}`.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'allow_self_registration' => 'portal.allow_self_registration',
        'require_email_verification' => 'portal.require_email_verification',
        'portal_title' => 'portal.title',
        'welcome_message' => 'portal.welcome_message',
        'primary_color' => 'portal.primary_color',
        'support_email' => 'portal.support_email',
        'key_prefix' => 'tickets.key_prefix',
        'reopen_window_days' => 'tickets.reopen_window_days',
        'auto_close_days' => 'tickets.auto_close_resolved_after_days',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Admin/Settings/Index', [
            'settings' => $this->currentValues(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'allow_self_registration' => ['boolean'],
            'require_email_verification' => ['boolean'],
            'portal_title' => ['required', 'string', 'max:120'],
            'welcome_message' => ['nullable', 'string', 'max:2000'],
            'primary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'key_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z][A-Z0-9]*$/'],
            'reopen_window_days' => ['required', 'integer', 'min:0', 'max:365'],
            'auto_close_days' => ['required', 'integer', 'min:0', 'max:365'],
        ], [
            'primary_color.regex' => __('admin.settings.color_format'),
            'key_prefix.regex' => __('admin.settings.prefix_format'),
        ]);

        $before = $this->currentValues();

        $this->settings->setMany(
            collect($data)
                ->mapWithKeys(fn (mixed $value, string $field) => [self::FIELDS[$field] => $value])
                ->all()
        );

        $this->audit->logGlobal(
            Setting::class,
            'settings.updated',
            'Updated application settings',
            array_diff_assoc($before, $data),
            array_diff_assoc($data, $before),
        );

        return back()->with('success', __('admin.settings.saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $key) {
            $values[$field] = $this->settings->get($key);
        }

        return $values;
    }
}
