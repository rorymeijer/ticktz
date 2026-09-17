<?php

declare(strict_types=1);

use App\Jobs\Updates\RunUpgrade;
use App\Models\Upgrade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** A GitHub release list with one release newer than the installed version. */
function offerRelease(string $tag = 'v1.4.0'): void
{
    config()->set('ticktz.version', '1.0.0');
    config()->set('ticktz.updates.enabled', true);
    config()->set('ticktz.updates.repository', 'rorymeijer/ticktz');
    Cache::flush();

    Http::fake(['api.github.com/*' => Http::response([[
        'tag_name' => $tag,
        'name' => 'Ticktz '.ltrim($tag, 'v'),
        'body' => 'Some notes.',
        'html_url' => 'https://github.com/rorymeijer/ticktz/releases/tag/'.$tag,
        'published_at' => '2026-01-02T10:00:00Z',
        'tarball_url' => 'https://api.github.com/repos/rorymeijer/ticktz/tarball/'.$tag,
        'draft' => false,
        'prerelease' => false,
        'assets' => [],
    ]])]);
}

it('shows the installed version and what is available', function () {
    offerRelease();

    $this->actingAs(makeAdmin())
        ->get('/admin/updates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Updates/Index')
            ->where('current', '1.0.0')
            ->where('release.version', '1.4.0')
            // 1.0.0 → 1.4.0 is the case the screen has to lead with.
            ->where('release.step', 'minor')
        );
});

it('offers nothing when the instance is already current', function () {
    offerRelease('v1.0.0');

    $this->actingAs(makeAdmin())
        ->get('/admin/updates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('release', null));
});

it('says the check is off rather than pretending it ran', function () {
    config()->set('ticktz.updates.enabled', false);
    Http::fake();

    $this->actingAs(makeAdmin())
        ->get('/admin/updates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('checkEnabled', false)->where('release', null));

    // The brief this was built to says no telemetry. An update check that ran
    // anyway, however useful, would be exactly that.
    Http::assertNothingSent();
});

it('is closed to anyone without updates.manage', function () {
    $this->actingAs(makeUserWithPermissions('settings.manage'))
        ->get('/admin/updates')
        ->assertForbidden();

    $this->actingAs(makeAgent())->get('/admin/updates')->assertForbidden();
});

it('is closed to a guest', function () {
    $this->get('/admin/updates')->assertRedirect('/login');
    $this->post('/admin/updates')->assertRedirect('/login');
    $this->getJson('/admin/updates/status')->assertUnauthorized();
});

it('queues an upgrade to the release that is on offer', function () {
    Queue::fake();
    offerRelease();
    config()->set('ticktz.updates.self_upgrade', true);

    $admin = makeAdmin();
    $this->actingAs($admin)->post('/admin/updates')->assertRedirect();

    $upgrade = Upgrade::query()->sole();

    expect($upgrade->to_version)->toBe('1.4.0')
        ->and($upgrade->from_version)->toBe('1.0.0')
        ->and($upgrade->status)->toBe(Upgrade::QUEUED)
        ->and($upgrade->requested_by)->toBe($admin->id);

    Queue::assertPushed(RunUpgrade::class);
});

it('takes no version from the request', function () {
    Queue::fake();
    offerRelease();
    config()->set('ticktz.updates.self_upgrade', true);

    // Everything an attacker might hope to steer with. None of it is read:
    // what gets installed is what the checker independently reports, and the
    // worker confirms that again before it touches a file.
    $this->actingAs(makeAdmin())->post('/admin/updates', [
        'version' => '9.9.9',
        'to_version' => '9.9.9',
        'url' => 'https://example.test/evil.zip',
        'archive_url' => 'https://example.test/evil.zip',
        'tag' => 'main',
    ])->assertRedirect();

    expect(Upgrade::query()->sole()->to_version)->toBe('1.4.0');
});

it('refuses a second upgrade while one is running', function () {
    Queue::fake();
    offerRelease();
    config()->set('ticktz.updates.self_upgrade', true);

    Upgrade::query()->create([
        'from_version' => '1.0.0',
        'to_version' => '1.4.0',
        'status' => Upgrade::RUNNING,
    ]);

    $this->actingAs(makeAdmin())->post('/admin/updates')->assertSessionHas('error');

    expect(Upgrade::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('refuses to queue anything on an installation that cannot replace its own code', function () {
    Queue::fake();
    offerRelease();
    config()->set('ticktz.updates.self_upgrade', false);

    $this->actingAs(makeAdmin())->post('/admin/updates')->assertSessionHas('error');

    expect(Upgrade::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses to queue when there is nothing newer', function () {
    Queue::fake();
    offerRelease('v1.0.0');
    config()->set('ticktz.updates.self_upgrade', true);

    $this->actingAs(makeAdmin())->post('/admin/updates')->assertSessionHas('error');

    expect(Upgrade::query()->count())->toBe(0);
});

it('reports progress as json for the screen to poll', function () {
    config()->set('ticktz.updates.enabled', true);

    $upgrade = Upgrade::query()->create([
        'from_version' => '1.0.0',
        'to_version' => '1.4.0',
        'status' => Upgrade::RUNNING,
    ]);

    $this->actingAs(makeAdmin())
        ->getJson('/admin/updates/status')
        ->assertOk()
        ->assertJsonPath('upgrade.id', $upgrade->id)
        ->assertJsonPath('upgrade.status', Upgrade::RUNNING)
        ->assertJsonPath('upgrade.finished', false);
});

it('does not report progress to somebody who may not upgrade', function () {
    $this->actingAs(makeAgent())->getJson('/admin/updates/status')->assertForbidden();
});
