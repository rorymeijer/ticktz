<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Api\DeliverWebhookSubscriptionJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Api\WebhookPayload;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webhook subscriptions, in the admin UI.
 *
 * The screen is built around the question an operator actually arrives with,
 * which is never "what are my webhooks" but "why did their system not hear
 * about this ticket". So the list carries the failure counters, each
 * subscription shows its recent deliveries with the response that came back,
 * and a test event can be sent without waiting for something real to happen.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $subscriptions = WebhookSubscription::query()
            ->orderBy('name')
            ->paginate((int) config('ticktz.per_page.admin'))
            ->through(fn (WebhookSubscription $subscription) => $subscription->toDisplayArray());

        $recent = WebhookDelivery::query()
            ->with('subscription')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (WebhookDelivery $delivery) => $delivery->toDisplayArray())
            ->all();

        return Inertia::render('Admin/Webhooks/Index', [
            'subscriptions' => $subscriptions,
            'deliveries' => $recent,
            'events' => array_map(
                fn (string $event) => ['key' => $event, 'label' => __('api.events.'.$event)],
                WebhookSubscription::EVENTS,
            ),
            'failureLimit' => WebhookSubscription::FAILURE_LIMIT,
            // Same one-shot handling as an API token's plaintext: a page prop
            // read off the flash, never a globally shared one.
            'newSecret' => $request->session()->get('webhookSecret'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $secret = Str::random(48);

        $subscription = WebhookSubscription::query()->create($validated + [
            'secret' => $secret,
            'created_by' => $request->user()?->getKey(),
        ]);

        $this->audit->actingAs($request->user())->created(
            $subscription,
            "Created webhook {$subscription->name}",
        );

        // Shown once, through the flash bag. It is a shared secret we have to
        // keep in order to sign with it, which is exactly why it must not be
        // readable from a list screen afterwards.
        return back()->with('webhookSecret', [
            'id' => $subscription->getKey(),
            'secret' => $secret,
        ]);
    }

    public function update(Request $request, WebhookSubscription $webhook): RedirectResponse
    {
        $validated = $request->validate($this->rules(partial: true));

        $wasActive = (bool) $webhook->is_active;

        $webhook->fill($validated);

        // Turning a self-disabled subscription back on has to clear the run of
        // failures with it, or the next single failure trips the limit again.
        if (! $wasActive && ($validated['is_active'] ?? false)) {
            $webhook->consecutive_failures = 0;
        }

        $webhook->save();

        $this->audit->actingAs($request->user())->updated($webhook, "Updated webhook {$webhook->name}");

        return back();
    }

    public function destroy(Request $request, WebhookSubscription $webhook): RedirectResponse
    {
        $name = $webhook->name;
        $webhook->delete();

        $this->audit->actingAs($request->user())->logGlobal(
            WebhookSubscription::class,
            'deleted',
            "Deleted webhook {$name}",
        );

        return back();
    }

    /**
     * Send a synthetic event, so an endpoint can be verified at the moment it
     * is configured rather than the first time something real happens.
     *
     * It goes through the same delivery row and the same job as a real event,
     * including the signature — a test that takes a different path proves the
     * test path works.
     */
    public function test(Request $request, WebhookSubscription $webhook): RedirectResponse
    {
        $delivery = WebhookDelivery::query()->create([
            'webhook_subscription_id' => $webhook->getKey(),
            'event' => 'ticket.created',
            'payload' => WebhookPayload::envelope('ticket.created', [
                'test' => true,
                'ticket' => [
                    'id' => 0,
                    'key' => 'TEST-1',
                    'subject' => 'Test delivery from Ticktz',
                    'status' => 'new',
                ],
                'actor' => WebhookPayload::person($request->user()),
            ]),
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        DeliverWebhookSubscriptionJob::dispatch($delivery->getKey());

        return back()->with('status', __('api.webhook_admin.test_sent'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'url' => [
                $required, 'url', 'max:2048',
                app()->isLocal() ? 'starts_with:http://,https://' : 'starts_with:https://',
            ],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookSubscription::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
