<?php

declare(strict_types=1);

use App\Models\WebhookSubscription;
use App\Support\ApiScopes;
use Illuminate\Support\Facades\Route;

/**
 * Keeps `docs/openapi.yaml` honest.
 *
 * A hand-written spec drifts the moment somebody adds a route and forgets the
 * docs, and a spec that is wrong is worse than none: an integrator trusts it,
 * writes against an endpoint that does not exist, and blames their own code.
 * So the route table is the source of truth and this test fails the build when
 * the two disagree — in either direction.
 *
 * The spec is read with a small extractor rather than a YAML library. It only
 * has to see two levels of a file we control, and pulling in a parser to check
 * our own documentation is a dependency with no other job.
 */
it('documents exactly the routes the API exposes', function (): void {
    $documented = documentedOperations();
    $actual = registeredApiOperations();

    // Named individually rather than compared as sets, so a failure says which
    // endpoint is missing instead of printing two lists to diff by eye.
    $undocumented = array_diff($actual, $documented);
    $phantom = array_diff($documented, $actual);

    expect($undocumented)->toBe([], 'Routes with no entry in docs/openapi.yaml: '.implode(', ', $undocumented))
        ->and($phantom)->toBe([], 'Documented endpoints that do not exist: '.implode(', ', $phantom));

    // A guard on the extractor itself: if the parse ever silently returns
    // nothing, the two diffs above would both be empty and the test would pass
    // while checking nothing at all.
    expect($documented)->not->toBeEmpty();
});

it('documents every scope the catalogue defines', function (): void {
    $spec = file_get_contents(base_path('docs/openapi.yaml'));

    foreach (ApiScopes::all() as $scope) {
        expect($spec)->toContain($scope);
    }
});

it('documents every webhook event that can fire', function (): void {
    $spec = file_get_contents(base_path('docs/openapi.yaml'));

    foreach (WebhookSubscription::EVENTS as $event) {
        expect($spec)->toContain($event);
    }
});

/**
 * "GET /tickets/{ticket}" for every operation in the spec's `paths:` block.
 *
 * @return array<int, string>
 */
function documentedOperations(): array
{
    $lines = file(base_path('docs/openapi.yaml'), FILE_IGNORE_NEW_LINES);
    $operations = [];
    $path = null;
    $inPaths = false;

    foreach ($lines as $line) {
        if ($line === 'paths:') {
            $inPaths = true;

            continue;
        }

        if (! $inPaths) {
            continue;
        }

        // Any other top-level key ends the block.
        if ($line !== '' && ! str_starts_with($line, ' ')) {
            break;
        }

        if (preg_match('#^  (/\S*):$#', $line, $matches) === 1) {
            $path = $matches[1];

            continue;
        }

        if ($path !== null && preg_match('/^    (get|post|put|patch|delete):$/', $line, $matches) === 1) {
            $operations[] = strtoupper($matches[1]).' '.$path;
        }
    }

    sort($operations);

    return $operations;
}

/**
 * The same shape, read off the router.
 *
 * @return array<int, string>
 */
function registeredApiOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        // `{ticket}` and `{ticket:key}` are the same endpoint as far as a
        // caller is concerned, so the binding hint is dropped.
        $path = '/'.preg_replace('/\{(\w+)[^}]*\}/', '{$1}', substr($route->uri(), strlen('api/v1/')));

        foreach ($route->methods() as $method) {
            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $operations[] = $method.' '.$path;
            }
        }
    }

    $operations = array_values(array_unique($operations));
    sort($operations);

    return $operations;
}
