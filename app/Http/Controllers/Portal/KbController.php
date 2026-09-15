<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Services\Kb\ArticleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The help centre a requester sees.
 *
 * Every query here goes through `visibleOnPortal()`. That scope is the whole
 * security surface of this controller: an internal article reaching the portal
 * is the one failure this feature must not have, so there is exactly one place
 * that decides what a requester may read, and nothing in here narrows or
 * widens it by hand.
 *
 * A draft, an archived article, an article in an internal category and an
 * article written for another language are all equally invisible, and a direct
 * URL to one answers 404 rather than 403 — the existence of an internal
 * article is itself information.
 */
class KbController extends Controller
{
    public function __construct(private readonly ArticleService $articles) {}

    public function index(Request $request): Response
    {
        $search = $request->string('q')->toString();

        $articles = KbArticle::query()
            ->visibleOnPortal()
            ->search($search)
            ->with('category')
            ->when(
                $request->filled('category'),
                fn ($query) => $query->whereHas(
                    'category',
                    fn ($category) => $category->where('slug', $request->string('category')),
                ),
            )
            // Without a search the most-read articles are the most useful
            // thing to put first; with one, relevance is whatever the index
            // says and the list is short enough to scan.
            ->when($search === '', fn ($query) => $query->orderByDesc('view_count'))
            ->orderBy('position')
            ->orderBy('title')
            ->paginate(config('ticktz.per_page.portal'))
            ->withQueryString()
            ->through(fn (KbArticle $article) => $article->toSummaryArray());

        return Inertia::render('Portal/Kb/Index', [
            'articles' => $articles,
            'categories' => KbCategory::query()
                ->publiclyVisible()
                ->withCount(['articles' => fn ($query) => $query->visibleOnPortal()])
                ->get()
                // A category with nothing in it is a dead end on a help page.
                ->filter(fn (KbCategory $category) => $category->articles_count > 0)
                ->map(fn (KbCategory $category) => $category->toSummaryArray())
                ->values()
                ->all(),
            'filters' => ['q' => $search, 'category' => $request->string('category')->toString() ?: null],
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $article = KbArticle::query()
            ->visibleOnPortal()
            ->with('category')
            ->where('slug', $slug)
            ->firstOrFail();

        $this->articles->recordView($article);

        return Inertia::render('Portal/Kb/Show', [
            'article' => $article->toDisplayArray(),
            'related' => $this->related($article),
        ]);
    }

    /**
     * Articles that might answer what the requester is about to ask.
     *
     * Called from the request form as they type a subject, which is why it
     * returns JSON rather than a page and why it returns so few: the point is
     * a nudge beside the form, not a second search results page.
     */
    public function suggest(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        // Two characters match half the knowledge base and help nobody.
        if (mb_strlen($term) < 3) {
            return response()->json(['articles' => []]);
        }

        $articles = KbArticle::query()
            ->visibleOnPortal()
            ->search($term)
            ->with('category')
            ->orderByDesc('view_count')
            ->limit(5)
            ->get()
            ->map(fn (KbArticle $article) => $article->toSummaryArray())
            ->all();

        return response()->json(['articles' => $articles]);
    }

    /**
     * More in the same category, for the bottom of an article.
     *
     * @return array<int, array<string, mixed>>
     */
    private function related(KbArticle $article): array
    {
        if (! $article->kb_category_id) {
            return [];
        }

        return KbArticle::query()
            ->visibleOnPortal()
            ->where('kb_category_id', $article->kb_category_id)
            ->whereKeyNot($article->getKey())
            ->orderByDesc('view_count')
            ->limit(5)
            ->get()
            ->map(fn (KbArticle $related) => $related->toSummaryArray())
            ->all();
    }
}
