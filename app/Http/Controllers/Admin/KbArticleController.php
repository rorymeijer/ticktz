<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbArticleVersion;
use App\Models\KbCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Kb\ArticleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Writing the knowledge base.
 *
 * `kb.manage` throughout: reading is a different permission and lives in the
 * agent and portal controllers. Bodies are never sanitised here — that happens
 * once, in {@see ArticleService}, so an import or a seeder gets the same
 * treatment as this form.
 */
class KbArticleController extends Controller
{
    public function __construct(
        private readonly ArticleService $articles,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('kb.manage');

        return Inertia::render('Admin/Kb/Index', [
            'articles' => KbArticle::query()
                ->with(['category', 'author', 'editor'])
                ->withCount('tickets')
                ->when(
                    $request->filled('q'),
                    fn ($query) => $query->search($request->string('q')->toString()),
                )
                ->when(
                    $request->filled('status'),
                    fn ($query) => $query->where('status', $request->string('status')),
                )
                ->when(
                    $request->filled('visibility'),
                    fn ($query) => $query->where('visibility', $request->string('visibility')),
                )
                ->when(
                    $request->filled('category'),
                    fn ($query) => $query->where('kb_category_id', $request->integer('category')),
                )
                ->latest('updated_at')
                ->paginate(config('ticktz.per_page.admin'))
                ->withQueryString()
                ->through(fn (KbArticle $article) => $article->toAdminArray()),
            'categories' => KbCategory::query()
                ->withCount('articles')
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (KbCategory $category) => $category->toAdminArray())
                ->all(),
            'filters' => [
                'q' => $request->string('q')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
                'visibility' => $request->string('visibility')->toString() ?: null,
                'category' => $request->integer('category') ?: null,
            ],
            'options' => [
                'statuses' => KbArticle::STATUSES,
                'visibilities' => KbArticle::VISIBILITIES,
                'locales' => array_keys(config('ticktz.locales')),
            ],
        ]);
    }

    /**
     * The editor, with the article's history beside it.
     */
    public function edit(KbArticle $article): Response
    {
        $this->authorize('kb.manage');

        $article->load(['category', 'author', 'editor', 'versions.editor', 'tickets:id,key,subject']);

        return Inertia::render('Admin/Kb/Edit', [
            'article' => $article->toAdminArray() + [
                'tickets' => $article->tickets->map(fn ($ticket) => [
                    'id' => $ticket->id,
                    'key' => $ticket->key,
                    'subject' => $ticket->subject,
                ])->all(),
            ],
            'versions' => $article->versions
                ->map(fn (KbArticleVersion $version) => $version->toSummaryArray())
                ->all(),
            'categories' => KbCategory::query()->orderBy('position')->orderBy('name')
                ->get()->map(fn (KbCategory $category) => $category->toAdminArray())->all(),
            'options' => [
                'statuses' => KbArticle::STATUSES,
                'visibilities' => KbArticle::VISIBILITIES,
                'locales' => array_keys(config('ticktz.locales')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('kb.manage');

        /** @var User $user */
        $user = $request->user();

        $article = $this->articles->create($this->validated($request), $user);

        return redirect()
            ->route('admin.kb.edit', $article)
            ->with('success', __('kb.articles.created', ['title' => $article->title]));
    }

    public function update(Request $request, KbArticle $article): RedirectResponse
    {
        $this->authorize('kb.manage');

        /** @var User $user */
        $user = $request->user();

        $this->articles->update($article, $this->validated($request, $article), $user);

        return back()->with('success', __('kb.articles.updated'));
    }

    public function destroy(KbArticle $article): RedirectResponse
    {
        $this->authorize('kb.manage');

        // Soft-deleted: an article a ticket was answered with is part of that
        // ticket's history, and erasing it would leave the link dangling.
        $this->audit->deleted($article, "Deleted article {$article->title}");
        $article->delete();

        return redirect()
            ->route('admin.kb.index')
            ->with('success', __('kb.articles.deleted'));
    }

    public function publish(Request $request, KbArticle $article): RedirectResponse
    {
        $this->authorize('kb.manage');

        /** @var User $user */
        $user = $request->user();

        $article->isPublished()
            ? $this->articles->unpublish($article, $user)
            : $this->articles->publish($article, $user);

        return back()->with('success', __(
            $article->fresh()->isPublished() ? 'kb.articles.published' : 'kb.articles.unpublished',
        ));
    }

    /**
     * Put an old version back. The current text is kept as a version first, so
     * this is itself undoable.
     */
    public function restore(Request $request, KbArticle $article, KbArticleVersion $version): RedirectResponse
    {
        $this->authorize('kb.manage');

        abort_if($version->kb_article_id !== $article->getKey(), 404);

        /** @var User $user */
        $user = $request->user();

        $this->articles->restore($article, $version, $user);

        return back()->with('success', __('kb.versions.restored', ['version' => $version->version]));
    }

    // -----------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('kb.manage');

        $category = KbCategory::query()->create($this->validatedCategory($request, null));

        $this->audit->created($category, "Created knowledge base category {$category->name}");

        return back()->with('success', __('kb.categories.created', ['name' => $category->name]));
    }

    public function updateCategory(Request $request, KbCategory $category): RedirectResponse
    {
        $this->authorize('kb.manage');

        $category->fill($this->validatedCategory($request, $category))->save();

        $this->audit->updated($category, "Updated knowledge base category {$category->name}");

        return back()->with('success', __('kb.categories.updated', ['name' => $category->name]));
    }

    public function destroyCategory(KbCategory $category): RedirectResponse
    {
        $this->authorize('kb.manage');

        // Articles survive their category — they become uncategorised rather
        // than disappearing, which the foreign key already arranges. Saying so
        // here is what stops somebody "fixing" it to a cascade later.
        $this->audit->deleted($category, "Deleted knowledge base category {$category->name}");
        $category->delete();

        return back()->with('success', __('kb.categories.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?KbArticle $article = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/'],
            'kb_category_id' => ['nullable', 'integer', Rule::exists('kb_categories', 'id')],
            'excerpt' => ['nullable', 'string', 'max:500'],
            // No sanitising rule here: the service cleans it, and a validation
            // rule that also cleaned would mean two places to keep in step.
            'body' => ['required', 'string', 'max:200000'],
            'status' => ['required', Rule::in(KbArticle::STATUSES)],
            'visibility' => ['required', Rule::in(KbArticle::VISIBILITIES)],
            'locale' => ['nullable', Rule::in(array_keys(config('ticktz.locales')))],
            'position' => ['integer', 'min:0', 'max:65535'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCategory(Request $request, ?KbCategory $category): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_translations' => ['array'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:128', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('kb_categories', 'slug')->ignore($category?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'description_translations' => ['array'],
            'description_translations.*' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            // One level only: a category cannot be its own parent, and a child
            // cannot itself have children.
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('kb_categories', 'id')->whereNull('parent_id'),
                Rule::notIn([$category?->getKey()]),
            ],
            'visibility' => ['required', Rule::in([KbCategory::PUBLIC, KbCategory::INTERNAL])],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:65535'],
        ]);
    }
}
