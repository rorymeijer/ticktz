<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Manual\ManualLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manual, and the `?` that opens it at the right place.
 *
 * Three ways in, all reading the same chapters: the whole manual, one chapter,
 * and the panel a `?` opens without leaving the page somebody is stuck on.
 * That last one is the point of the exercise — a manual you have to go and
 * find is a manual nobody reads at the moment they need it.
 */
class ManualController extends Controller
{
    public function __construct(private readonly ManualLibrary $manual) {}

    public function index(Request $request): Response
    {
        /** @var User $reader */
        $reader = $request->user();

        return Inertia::render('Manual/Index', [
            'chapters' => $this->manual->tableOfContents($reader),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        /** @var User $reader */
        $reader = $request->user();

        $chapter = $this->manual->chapter($slug, $reader);

        // 404 whether the chapter is missing or merely not this reader's, so
        // the manual does not become a way to enumerate what Ticktz can do.
        abort_if($chapter === null, 404);

        return Inertia::render('Manual/Show', [
            'chapter' => $chapter,
            'chapters' => $this->manual->tableOfContents($reader),
        ]);
    }

    /**
     * What the `?` on a screen shows.
     *
     * Asked by page component rather than by URL, because the component is
     * exactly "which screen am I looking at" and a URL is not: one ticket page
     * has as many URLs as there are tickets.
     */
    public function panel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['required', 'string', 'max:120'],
        ]);

        /** @var User $reader */
        $reader = $request->user();

        return response()->json([
            'chapter' => $this->manual->forPage($data['page'], $reader),
        ]);
    }
}
