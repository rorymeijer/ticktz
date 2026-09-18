<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Install\DatabaseChoice;
use App\Services\Install\DatabaseTester;
use App\Services\Install\RequirementsChecker;
use App\Support\Install\InstallationState;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

beforeEach(function (): void {
    // The suite runs against a migrated database with no users, which is
    // exactly the state `looksAlreadyRunning()` treats as *not* installed —
    // so the wizard is reachable without any setup here.
    InstallationState::forget();
});

afterEach(function (): void {
    InstallationState::forget();
    @unlink(InstallationState::path());
});

/*
|--------------------------------------------------------------------------
| Reachability
|--------------------------------------------------------------------------
*/

it('sends an un-installed instance to the wizard', function (): void {
    get('/dashboard')->assertRedirect(route('install.show'));
    get('/portal')->assertRedirect(route('install.show'));
    get('/')->assertRedirect(route('install.show'));
});

it('tells an API caller in JSON rather than redirecting it into HTML', function (): void {
    // A script that receives a 302 to a setup page has learned nothing.
    getJson('/api/v1/tickets')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'not_installed');
});

it('keeps the health endpoint answering during a first deployment', function (): void {
    // An orchestrator watching this must see the instance, not a redirect it
    // will follow into an HTML page.
    get('/health')->assertOk()->assertJsonStructure(['status', 'checks']);
});

it('renders the wizard', function (): void {
    get('/install')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Install/Wizard')
            ->has('requirements.required')
            ->has('database.bundledAvailable')
            ->has('timezones')
        );
});

/*
|--------------------------------------------------------------------------
| Closing the door
|--------------------------------------------------------------------------
*/

it('closes every acting endpoint once the instance is installed', function (): void {
    InstallationState::markInstalled();

    // The wizard is unauthenticated by necessity and creates an administrator.
    // Left open on a running instance it is a complete takeover.
    post('/install/database', [])->assertNotFound();
    post('/install', [])->assertNotFound();
});

it('sends somebody who lands on the wizard afterwards to the login page', function (): void {
    InstallationState::markInstalled();

    get('/install')->assertRedirect(route('login'));
});

it('adopts an instance that was already running before the installer existed', function (): void {
    // Anybody upgrading has a migrated database with users in it and no
    // marker. Dropping their working desk into a setup wizard would be a
    // catastrophe, so that state counts as installed.
    expect(InstallationState::isInstalled())->toBeFalse();

    seedRbac();
    User::factory()->create();
    InstallationState::forget();

    expect(InstallationState::isInstalled())->toBeTrue()
        ->and(InstallationState::details())->toHaveKey('adopted');
});

it('does not adopt a half-migrated database with no accounts', function (): void {
    // Migrations applied but nobody created: a failed install, and the wizard
    // is exactly what that wants.
    expect(InstallationState::isInstalled())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses an administrator password that is too short', function (): void {
    post('/install', installPayload(['admin' => ['password' => 'short']]))
        ->assertSessionHasErrors('admin.password');

    expect(User::query()->count())->toBe(0);
});

it('refuses a database name that is not a plain identifier', function (): void {
    // It is interpolated into a CREATE TABLE probe, so it is constrained at
    // the edge rather than escaped four layers in.
    post('/install', installPayload(['database' => 'ticktz; DROP TABLE users']))
        ->assertSessionHasErrors('database');
});

it('refuses a URL that is not a URL', function (): void {
    post('/install', installPayload(['app' => ['url' => 'not a url']]))
        ->assertSessionHasErrors('app.url');
});

it('requires the SMTP details when mail is switched on', function (): void {
    post('/install', installPayload(['mail' => ['enabled' => true]]))
        ->assertSessionHasErrors(['mail.host', 'mail.from_address']);
});

it('accepts a well-formed payload and fails on the connection, not the shape', function (): void {
    // A full install needs a MySQL server, which the suite does not have. What
    // this pins is that a correct payload passes every rule and gets as far as
    // opening a connection — so a future change that breaks the shape shows up
    // here rather than in somebody's first deployment.
    // `host` is excluded on purpose: it is where a *connection* failure is
    // reported, so that it appears next to the field somebody can fix. Whether
    // a MySQL server happens to answer is not this test's business.
    post('/install', installPayload())
        ->assertSessionDoesntHaveErrors([
            'database_choice', 'port', 'database', 'username',
            'app.name', 'app.url', 'app.locale', 'app.timezone', 'app.ticket_prefix',
            'admin.name', 'admin.email', 'admin.password',
            'mail.enabled', 'mail.host', 'mail.from_address',
        ]);

    expect(User::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The pieces
|--------------------------------------------------------------------------
*/

it('reports requirements this very process satisfies', function (): void {
    $result = app(RequirementsChecker::class)->check();

    // The suite is running, so every required extension is loaded by
    // definition. A failure here means the list asks for something the
    // application does not actually need.
    expect($result['ok'])->toBeTrue()
        ->and(collect($result['required'])->where('ok', false)->pluck('label')->all())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The built-in database
|--------------------------------------------------------------------------
|
| Picking it used to prefill four fields and leave the fifth — the password —
| empty, because it is the one value that must not be sent to an
| unauthenticated page. Pressing *Test connection* then offered the operator
| "the server refused that username or password" for a password they had never
| been asked for and could not have known.
|
| So the bundled database now asks for nothing at all, and the server reads
| every value, the password included, from its own environment.
*/

/** Make the environment look like the compose stack, and put it back after. */
function withBundledEnvironment(Closure $body): void
{
    $before = [];

    foreach (['DB_HOST' => 'mysql', 'DB_PORT' => '3306', 'DB_DATABASE' => 'ticktz', 'DB_USERNAME' => 'ticktz', 'DB_PASSWORD' => 'from-the-compose-file'] as $key => $value) {
        $before[$key] = getenv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $body();
    } finally {
        foreach ($before as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

/** Stand in for the tester and record what it was asked to connect with. */
function recordingTester(): stdClass
{
    $box = new stdClass;
    $box->seen = null;

    $tester = Mockery::mock(DatabaseTester::class);
    $tester->shouldReceive('test')->andReturnUsing(function (array $credentials) use ($box): array {
        $box->seen = $credentials;

        return ['ok' => true, 'reason' => null, 'detail' => null, 'server' => '8.4.0', 'writable' => true, 'empty' => true];
    });

    app()->instance(DatabaseTester::class, $tester);

    return $box;
}

it('asks for nothing when the built-in database is chosen', function (): void {
    withBundledEnvironment(function (): void {
        recordingTester();

        // No host, no port, no name, no user, no password. This is the whole
        // request the screen now sends.
        post('/install/database', ['database_choice' => 'bundled'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    });
});

it('connects with the environment rather than anything the browser sent', function (): void {
    withBundledEnvironment(function (): void {
        $spy = recordingTester();

        // A request that names the bundled database but carries somebody
        // else's server. Accepting it would make an unauthenticated endpoint
        // into a way to point the installer wherever the caller likes.
        post('/install/database', [
            'database_choice' => 'bundled',
            'host' => 'attacker.example.org',
            'port' => 1234,
            'database' => 'somewhere_else',
            'username' => 'root',
            'password' => 'guessed',
        ])->assertOk();

        expect($spy->seen)->toBe([
            'host' => 'mysql',
            'port' => '3306',
            'database' => 'ticktz',
            'username' => 'ticktz',
            'password' => 'from-the-compose-file',
        ]);
    });
});

it('installs against the built-in database without being given its credentials', function (): void {
    withBundledEnvironment(function (): void {
        $payload = installPayload(['database_choice' => 'bundled']);

        unset($payload['host'], $payload['port'], $payload['database'], $payload['username'], $payload['password']);

        // Every one of the five, `host` included. There is no `mysql` for this
        // suite to reach, so the install does fail — but on a screen with no
        // fields the failure belongs to the instance rather than to a field,
        // and the next test pins where it goes instead.
        post('/install', $payload)
            ->assertSessionDoesntHaveErrors(['database_choice', 'host', 'port', 'database', 'username', 'password']);
    });
});

it('reports a built-in database that stops answering where it can be read', function (): void {
    withBundledEnvironment(function (): void {
        // The connection is tested again at the end, and this suite has no
        // `mysql`, so this is that failure. It used to be reported on `host` —
        // which the bundled screen no longer renders, so the button would have
        // appeared to do nothing and going back a step would have explained
        // nothing either.
        $payload = installPayload(['database_choice' => 'bundled']);

        unset($payload['host'], $payload['port'], $payload['database'], $payload['username'], $payload['password']);

        post('/install', $payload)->assertSessionHasErrors('install');
    });
});

it('refuses the built-in database where there is none', function (): void {
    // Loopback, so `bundledIsAvailable()` says no. Without this the installer
    // would read an empty environment and report a refused password for a
    // server that was never there.
    putenv('DB_HOST=127.0.0.1');
    $_ENV['DB_HOST'] = '127.0.0.1';

    post('/install/database', ['database_choice' => 'bundled'])
        ->assertSessionHasErrors('database_choice');
});

it('still asks for every field when the database is somebody else\'s', function (): void {
    post('/install/database', ['database_choice' => 'external'])
        ->assertSessionHasErrors(['host', 'port', 'database', 'username']);
});

it('keeps the bundled password off the page', function (): void {
    withBundledEnvironment(function (): void {
        // What the screen is given, which is what ends up in the HTML.
        expect(DatabaseChoice::defaults())->not->toHaveKey('password')
            ->and(DatabaseChoice::bundledCredentials()['password'])->toBe('from-the-compose-file');

        get('/install')->assertOk()->assertDontSee('from-the-compose-file');
    });
});

it('does not offer the built-in database when there is visibly no container', function (): void {
    config()->set('database.connections.mysql.host', '127.0.0.1');
    putenv('DB_HOST=127.0.0.1');
    $_ENV['DB_HOST'] = '127.0.0.1';

    expect(DatabaseChoice::bundledIsAvailable())->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function installPayload(array $overrides = []): array
{
    $payload = [
        'database_choice' => 'external',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'ticktz_test_target',
        'username' => 'ticktz',
        'password' => 'secret',
        'app' => [
            'name' => 'Zandvliet Servicedesk',
            'url' => 'https://desk.example.org',
            'locale' => 'nl',
            'timezone' => 'Europe/Amsterdam',
            'ticket_prefix' => 'ZVD',
        ],
        'admin' => [
            'name' => 'Rianne Bakker',
            'email' => 'rianne@example.org',
            'password' => 'correct-horse-9-battery',
        ],
        'mail' => ['enabled' => false],
    ];

    foreach ($overrides as $key => $value) {
        $payload[$key] = is_array($value) && is_array($payload[$key] ?? null)
            ? array_merge($payload[$key], $value)
            : $value;
    }

    return $payload;
}
