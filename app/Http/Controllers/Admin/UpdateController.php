<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Updates\RunUpgrade;
use App\Models\Upgrade;
use App\Services\Updates\ReleaseChecker;
use App\Services\Updates\UpgradeMonitor;
use App\Services\Updates\UpgradePreflight;
use App\Services\Updates\UpgradeSwap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration → Updates.
 *
 * What this screen can and cannot do is the whole point of it. It can find out
 * what is published, say whether this instance is able to install it, and ask
 * for one named version to be installed. It cannot install anything: the
 * request writes a row and hands it to the worker, because a web-facing PHP
 * process must never be able to write the application's own code.
 *
 * So there is no endpoint here that takes a URL, a path or a version of the
 * caller's choosing. {@see store()} takes nothing at all — what gets installed
 * is whatever the checker independently says is on offer, and the worker
 * confirms that again before it touches anything.
 */
class UpdateController extends Controller
{
    public function index(
        Request $request,
        ReleaseChecker $checker,
        UpgradePreflight $preflight,
        UpgradeMonitor $monitor,
    ): Response {
        $this->authorize('updates.manage');

        $release = $checker->latest(refresh: $request->boolean('refresh'));
        $current = $checker->current();

        return Inertia::render('Admin/Updates/Index', [
            'current' => (string) config('ticktz.version'),
            'checkEnabled' => $checker->enabled(),
            'repository' => (string) config('ticktz.updates.repository'),
            'release' => $release !== null && $checker->isUpgrade($release)
                ? $release->toArray() + [
                    'step' => $current === null ? 'unknown' : $release->version->stepFrom($current),
                ]
                : null,
            'checkedAt' => $release === null ? null : now()->toIso8601String(),
            'canSelfUpgrade' => $preflight->canSelfUpgrade() && UpgradeSwap::isAvailable(),
            'checks' => $preflight->run(),
            'upgrade' => $this->latestUpgrade($monitor),
            'history' => Upgrade::query()
                ->with('requester:id,name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Upgrade $upgrade) => $this->present($upgrade))
                ->all(),
        ]);
    }

    /**
     * Ask for the offered release to be installed.
     *
     * Deliberately takes no input. The version installed is the one the checker
     * reports, read here and confirmed again in the worker — so a request
     * cannot name a tag, a branch, an archive or a downgrade, and the worst a
     * forged request can achieve is asking for the upgrade the screen was
     * already offering.
     */
    public function store(ReleaseChecker $checker, UpgradePreflight $preflight): RedirectResponse
    {
        $this->authorize('updates.manage');

        $release = $checker->latest();

        if ($release === null || ! $checker->isUpgrade($release)) {
            return back()->with('error', __('admin.updates.errors.nothing_to_install'));
        }

        if (! $preflight->canSelfUpgrade() || ! UpgradeSwap::isAvailable()) {
            return back()->with('error', __('admin.updates.errors.cannot_self_upgrade'));
        }

        // One at a time, and never a second while the first is still moving
        // files around.
        $running = Upgrade::query()
            ->whereIn('status', [Upgrade::QUEUED, Upgrade::RUNNING])
            ->exists();

        if ($running) {
            return back()->with('error', __('admin.updates.errors.already_running'));
        }

        $upgrade = Upgrade::query()->create([
            'from_version' => (string) config('ticktz.version'),
            'to_version' => (string) $release->version,
            'status' => Upgrade::QUEUED,
            'requested_by' => auth()->id(),
        ]);

        RunUpgrade::dispatch($upgrade->getKey());

        return back()->with('success', __('admin.updates.queued'));
    }

    /**
     * What the running upgrade is doing.
     *
     * Polled by the screen. It has to be a separate endpoint rather than a
     * reload of {@see index()}: for part of an upgrade the application on disk
     * is being replaced, and the smallest possible response is the one most
     * likely to survive that. It also reconciles — the swap could not write to
     * the database while it ran, so this is where its status file becomes a row.
     */
    public function show(UpgradeMonitor $monitor): JsonResponse
    {
        $this->authorize('updates.manage');

        return response()->json(['upgrade' => $this->latestUpgrade($monitor)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestUpgrade(UpgradeMonitor $monitor): ?array
    {
        $upgrade = Upgrade::query()->with('requester:id,name')->latest('id')->first();

        if ($upgrade === null) {
            return null;
        }

        return $this->present($monitor->reconcile($upgrade));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Upgrade $upgrade): array
    {
        return [
            'id' => $upgrade->getKey(),
            'from_version' => $upgrade->from_version,
            'to_version' => $upgrade->to_version,
            'status' => $upgrade->status,
            'log' => $upgrade->log,
            'error' => $upgrade->error,
            'finished' => $upgrade->isFinished(),
            'abandoned' => $upgrade->looksAbandoned(),
            'requester' => $upgrade->requester?->name,
            'started_at' => $upgrade->started_at?->toIso8601String(),
            'finished_at' => $upgrade->finished_at?->toIso8601String(),
            'created_at' => $upgrade->created_at?->toIso8601String(),
        ];
    }
}
