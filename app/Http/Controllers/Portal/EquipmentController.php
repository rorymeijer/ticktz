<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "What have I got?" — the requester's own equipment.
 *
 * Read-only, and narrower than the agent view by a long way: a requester sees
 * what is assigned to them, plus their colleagues' when their organisation
 * shares tickets, and nothing else. No purchase cost, no supplier, no internal
 * notes — {@see Asset::toPortalArray()} decides that, not this controller.
 *
 * It exists because half the tickets a desk gets about a machine open with the
 * requester not knowing what the machine is called.
 */
class EquipmentController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->loadMissing('organization');

        return Inertia::render('Portal/Equipment', [
            'assets' => Asset::query()
                ->visibleToRequester($user)
                ->whereIn('status', Asset::LIVE_STATUSES)
                ->with(['type', 'assignee'])
                ->orderBy('asset_tag')
                ->paginate(config('ticktz.per_page.portal'))
                ->through(fn (Asset $asset) => $asset->toPortalArray($user)),
        ]);
    }
}
