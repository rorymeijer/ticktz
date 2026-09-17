<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Kb\ArticleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The knowledge base as an agent uses it: to find an answer, and to record
 * which answer they used.
 *
 * Reading goes through `visibleToAgent()`, which widens by permission rather
 * than narrowing — an agent without `kb.view.internal` gets exactly the portal
 * view, and one without `kb.manage` never sees a draft. Linking is what turns
 * "what do we get asked most" from a guess into a query.
 */
class KbController extends Controller
{
    public function __construct(private readonly ArticleService $articles) {}

    public function index(Request $request): Response
    {
        $this->authorize('kb.view');

        /** @var User $user */
        $user = $request->user();
        $search = $request->string('q')->toString();

        return Inertia::render('Agent/Kb/Index', [
            'articles' => KbArticle::query()
                ->visibleToAgent($user)
                ->search($search)
                ->with('category')
                ->when(
                    $request->filled('category'),
                    fn ($query) => $query->whereHas(
                        'category',
                        fn ($category) => $category->where('slug', $request->string('category')),
                    ),
                )
                ->when($search === '', fn ($query) => $query->orderByDesc('view_count'))
                ->orderBy('title')
                ->paginate(config('ticktz.per_page.tickets'))
                ->withQueryString()
                ->through(fn (KbArticle $article) => $article->toSummaryArray()),
            'categories' => KbCategory::query()
                ->where('is_active', true)
                ->when(
                    ! $user->hasPermission('kb.view.internal'),
                    fn ($query) => $query->where('visibility', KbCategory::PUBLIC),
                )
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (KbCategory $category) => $category->toSummaryArray())
                ->all(),
            'filters' => ['q' => $search, 'category' => $request->string('category')->toString() ?: null],
            'can' => ['manage' => $user->can('kb.manage')],
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $this->authorize('kb.view');

        /** @var User $user */
        $user = $request->user();

        $article = KbArticle::query()
            ->visibleToAgent($user)
            ->with(['category', 'author'])
            ->where('slug', $slug)
            ->firstOrFail();

        $this->articles->recordView($article);

        return Inertia::render('Agent/Kb/Show', [
            'article' => $article->toDisplayArray(),
            'can' => ['manage' => $user->can('kb.manage')],
        ]);
    }

    /**
     * Articles an agent might answer this ticket with.
     *
     * Seeded from the ticket's own subject when no term is given, which is the
     * difference between a search box an agent has to think about and one that
     * has already had a go.
     */
    public function suggest(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();

        $term = trim($request->string('q')->toString()) ?: $ticket->subject;

        $articles = KbArticle::query()
            ->visibleToAgent($user)
            ->search($term)
            ->with('category')
            // Already linked is not a suggestion.
            ->whereDoesntHave('tickets', fn ($query) => $query->whereKey($ticket->getKey()))
            ->orderByDesc('view_count')
            ->limit(6)
            ->get()
            ->map(fn (KbArticle $article) => $article->toSummaryArray())
            ->all();

        return response()->json(['articles' => $articles]);
    }

    public function link(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);
        $this->authorize('kb.view');

        $data = $request->validate([
            'kb_article_id' => ['required', 'integer', 'exists:kb_articles,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Looked up through the agent's own scope: an agent who cannot read an
        // internal article cannot attach one either, and guessing an id must
        // not be a way around that.
        $article = KbArticle::query()
            ->visibleToAgent($user)
            ->whereKey($data['kb_article_id'])
            ->firstOrFail();

        $this->articles->linkToTicket($article, $ticket, $user);

        return back()->with('success', __('kb.linked', ['title' => $article->title]));
    }

    public function unlink(Request $request, Ticket $ticket, KbArticle $article): RedirectResponse
    {
        $this->authorize('update', $ticket);

        /** @var User $user */
        $user = $request->user();

        $this->articles->unlinkFromTicket($article, $ticket, $user);

        return back()->with('success', __('kb.unlinked'));
    }
}
