<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Managing your own webhook endpoints over the API.
 *
 * A caller can register where to be told about things, which is what makes the
 * API usable without polling. Two rules are worth stating:
 *
 * - The secret is **generated here** and returned exactly once, on create. A
 *   caller-supplied secret is usually a password somebody already uses
 *   elsewhere, and a secret we can show again is one that will be read out of
 *   a list screen by somebody who should not have it.
 * - The URL must be https outside local development. A signed payload sent
 *   over http is still a ticket's subject and the requester's e-mail address
 *   crossing the network in the clear.
 */
class WebhookController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = WebhookSubscription::query()
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return new JsonResponse([
            'data' => array_map(
                static fn (WebhookSubscription $subscription) => $subscription->toDisplayArray(),
                $subscriptions->items(),
            ),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $secret = Str::random(48);

        $subscription = WebhookSubscription::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'url' => $validated['url'],
            'events' => $validated['events'],
            'secret' => $secret,
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()?->getKey(),
        ]);

        return new JsonResponse([
            'data' => $subscription->toDisplayArray() + [
                // The one and only time this is readable.
                'secret' => $secret,
            ],
        ], 201);
    }

    public function update(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $validated = $request->validate($this->rules(partial: true));

        $webhook->fill($validated);

        // Re-enabling a subscription that switched itself off after a run of
        // failures has to clear the counter too, or it trips again on the next
        // failure instead of getting a fresh run of attempts.
        if (($validated['is_active'] ?? false) && ! $webhook->getOriginal('is_active')) {
            $webhook->consecutive_failures = 0;
        }

        $webhook->save();

        return new JsonResponse(['data' => $webhook->toDisplayArray()]);
    }

    public function destroy(WebhookSubscription $webhook): JsonResponse
    {
        $webhook->delete();

        return new JsonResponse(status: 204);
    }

    public function deliveries(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $deliveries = $webhook->deliveries()
            ->latest('id')
            ->paginate($this->perPage($request));

        return new JsonResponse([
            'data' => array_map(
                static fn ($delivery) => $delivery->toDisplayArray(),
                $deliveries->items(),
            ),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'total' => $deliveries->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'url' => [
                $required, 'url', 'max:2048',
                // Enforced by scheme rather than by asking nicely in the docs.
                app()->isLocal() ? 'starts_with:http://,https://' : 'starts_with:https://',
            ],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookSubscription::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
