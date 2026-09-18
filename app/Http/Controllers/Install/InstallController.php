<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Install\DatabaseChoice;
use App\Services\Install\DatabaseTester;
use App\Services\Install\Installer;
use App\Services\Install\RequirementsChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The setup wizard.
 *
 * One Inertia page holding every step, because the answers to earlier steps
 * change what later ones should show and a multi-request wizard would need
 * somewhere to keep them. There is nowhere to keep them: sessions are in
 * Redis, which the operator has not configured yet, and putting database
 * credentials in a session on a half-configured instance is a worse idea than
 * holding them in the browser until the one request that uses them.
 *
 * So nothing is persisted until `store()`, which does the whole install in a
 * single transaction of intent. A closed tab loses nothing but typing.
 */
class InstallController extends Controller
{
    public function __construct(
        private readonly RequirementsChecker $requirements,
        private readonly DatabaseTester $tester,
        private readonly Installer $installer,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Install/Wizard', [
            'requirements' => $this->requirements->check(),
            'database' => [
                'bundledAvailable' => DatabaseChoice::bundledIsAvailable(),
                'defaults' => DatabaseChoice::defaults(),
            ],
            'defaults' => [
                'url' => rtrim((string) config('app.url'), '/'),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
                'name' => config('app.name'),
            ],
            'timezones' => $this->timezones(),
            'locales' => collect(config('ticktz.locales'))
                ->map(fn (array $locale, string $code) => ['code' => $code, 'native' => $locale['native']])
                ->values()
                ->all(),
            'version' => config('ticktz.version'),
        ]);
    }

    /**
     * Test a set of database credentials without committing to them.
     *
     * Rate-limited in the route, because this endpoint connects to an
     * arbitrary host and port on request and an unauthenticated one that did
     * not would be a port scanner. It is only reachable before installation,
     * which is a window of minutes on a fresh deployment, but "briefly
     * available" is not the same as "safe".
     */
    public function testDatabase(Request $request): JsonResponse
    {
        $choice = $this->choice($request);

        $validated = $request->validate([
            'database_choice' => $this->choiceRules(),
            ...$this->databaseRules($choice),
        ]);

        return new JsonResponse($this->tester->test($this->credentials($choice, $validated)));
    }

    public function store(Request $request): RedirectResponse
    {
        $choice = $this->choice($request);

        $validated = $request->validate([
            ...$this->databaseRules($choice),

            'database_choice' => $this->choiceRules(),

            'app.name' => ['required', 'string', 'max:64'],
            'app.url' => ['required', 'url', 'max:255'],
            'app.locale' => ['required', Rule::in(array_keys(config('ticktz.locales')))],
            'app.timezone' => ['required', 'timezone'],
            'app.ticket_prefix' => ['nullable', 'string', 'max:8', 'regex:/^[A-Z][A-Z0-9]*$/'],

            'admin.name' => ['required', 'string', 'max:255'],
            'admin.email' => ['required', 'email', 'max:255'],
            // The first account on the instance has every permission, so it
            // gets a longer minimum than the default eight characters.
            //
            // Deliberately *not* `uncompromised()`. That rule calls the Have I
            // Been Pwned API, which makes installation depend on reaching a
            // third party over the internet — so an air-gapped or firewalled
            // deployment could not create its own administrator, and every
            // install would make an outbound call the operator never asked
            // for. Neither is acceptable in a product whose premise is that
            // nothing leaves your infrastructure.
            'admin.password' => ['required', 'string', Password::min(12)->letters()->numbers()],

            'mail.enabled' => ['required', 'boolean'],
            'mail.host' => ['required_if:mail.enabled,true', 'nullable', 'string', 'max:255'],
            'mail.port' => ['required_if:mail.enabled,true', 'nullable', 'integer', 'min:1', 'max:65535'],
            'mail.username' => ['nullable', 'string', 'max:255'],
            'mail.password' => ['nullable', 'string', 'max:255'],
            'mail.scheme' => ['nullable', Rule::in(['tls', 'ssl', ''])],
            'mail.from_address' => ['required_if:mail.enabled,true', 'nullable', 'email', 'max:255'],
        ]);

        $payload = [
            'database' => $this->credentials($choice, $validated),
            'database_choice' => $validated['database_choice'],
            'app' => $validated['app'],
            'admin' => $validated['admin'] + ['locale' => $validated['app']['locale']],
            'mail' => $validated['mail'],
        ];

        try {
            $this->installer->run($payload);
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'database:')) {
                $message = __('install.database.errors.'.substr($exception->getMessage(), 9));

                // Where it can be read. For a database the operator typed in,
                // that is beside the host field they can correct. The bundled
                // one has no fields on the screen at all, so the same error
                // put on `host` would be attached to nothing — the button
                // would appear to do nothing at all, and going back a step
                // would explain nothing either. It is not a field-level
                // problem there anyway: the container the stack started has
                // stopped answering.
                return back()->withErrors(
                    $choice === DatabaseChoice::Bundled ? ['install' => $message] : ['host' => $message]
                );
            }

            return back()->withErrors(['install' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            // Everything else: the message can name internal paths and
            // credentials, so it goes to the log and the operator gets a
            // pointer to it rather than the text.
            Log::error('Installation failed', ['exception' => $exception]);

            return back()->withErrors(['install' => __('install.errors.failed')]);
        }

        return redirect()->route('login')->with('success', __('install.done.flash'));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Which database the request is asking for.
     *
     * Read before validation because it decides what else there is to
     * validate. An unrecognised value becomes External, whose rules demand
     * every field — so a malformed choice fails on the fields it did not send
     * rather than being quietly treated as the bundled one.
     */
    private function choice(Request $request): DatabaseChoice
    {
        return DatabaseChoice::tryFrom((string) $request->input('database_choice'))
            ?? DatabaseChoice::External;
    }

    /**
     * @return array<int, mixed>
     */
    private function choiceRules(): array
    {
        return [
            'required',
            Rule::enum(DatabaseChoice::class),
            // Asking for the bundled database where there is none would read
            // an empty environment and connect to nothing, reporting a refused
            // password for a server that was never there.
            function (string $attribute, mixed $value, callable $fail): void {
                if ($value === DatabaseChoice::Bundled->value && ! DatabaseChoice::bundledIsAvailable()) {
                    $fail(__('install.database.bundled_unavailable'));
                }
            },
        ];
    }

    /**
     * The fields the operator has to supply — none, for the bundled database.
     *
     * @return array<string, array<int, string>>
     */
    private function databaseRules(DatabaseChoice $choice): array
    {
        if ($choice === DatabaseChoice::Bundled) {
            return [];
        }

        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * What to connect with.
     *
     * For the bundled database this ignores the request entirely and reads the
     * environment. The values are the compose file's own, the password among
     * them was never sent to the browser, and accepting a submitted one would
     * turn an unauthenticated endpoint into a way to point the installer at
     * somebody else's server.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function credentials(DatabaseChoice $choice, array $validated): array
    {
        if ($choice === DatabaseChoice::Bundled) {
            return DatabaseChoice::bundledCredentials();
        }

        return [
            'host' => (string) $validated['host'],
            'port' => (string) $validated['port'],
            'database' => (string) $validated['database'],
            'username' => (string) $validated['username'],
            'password' => (string) ($validated['password'] ?? ''),
        ];
    }

    /**
     * Grouped by region so the list is navigable. A flat 400-entry select is
     * technically complete and practically unusable.
     *
     * @return array<int, array<string, string>>
     */
    private function timezones(): array
    {
        return collect(\DateTimeZone::listIdentifiers())
            ->map(fn (string $zone) => [
                'value' => $zone,
                'group' => str_contains($zone, '/') ? explode('/', $zone)[0] : 'Other',
                'label' => str_replace('_', ' ', $zone),
            ])
            ->values()
            ->all();
    }
}
