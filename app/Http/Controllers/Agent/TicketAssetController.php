<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assets\AssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Attaching an asset to the ticket it is about.
 *
 * This is the half of a CMDB that earns its keep. A register nothing points at
 * answers "what do we own"; one attached to tickets answers "what keeps
 * breaking", which is the question that changes a purchasing decision.
 */
class TicketAssetController extends Controller
{
    public function __construct(private readonly AssetService $assets) {}

    public function store(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);
        $this->authorize('assets.view');

        $data = $request->validate([
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')],
        ]);

        /** @var User $user */
        $user = $request->user();

        $asset = Asset::query()->findOrFail($data['asset_id']);

        $this->assets->linkToTicket($asset, $ticket, $user);

        return back()->with('success', __('assets.flash.linked', ['tag' => $asset->asset_tag]));
    }

    public function destroy(Request $request, Ticket $ticket, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $ticket);

        /** @var User $user */
        $user = $request->user();

        $this->assets->unlinkFromTicket($asset, $ticket, $user);

        return back()->with('success', __('assets.flash.unlinked'));
    }
}
