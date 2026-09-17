<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\KbArticleResource;
use App\Models\KbArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Knowledge base articles, read-only.
 *
 * Visibility runs through the same two scopes the portal and the console use.
 * An agent token with `kb.view.internal` reads internal articles; a
 * requester's token gets the published public ones and nothing else — which is
 * the point, because this is the endpoint a chatbot or an intranet page will
 * be pointed at, and those are read by whoever walks past them.
 *
 * Bodies come from `show`, not from `index`: fifty articles with fifty full
 * bodies is a megabyte of response for a list of titles.
 */
class KbController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = KbArticle::query()->with('category');

        $this->scopeForReader($query, $user, $request);

        if ($search = $request->string('search')->toString()) {
            $query->search($search);
        }

        if ($category = $request->string('category')->toString()) {
            $query->whereHas('category', fn ($categories) => $categories->where('slug', $category));
        }

        return KbArticleResource::collection(
            $query->orderByDesc('published_at')->paginate($this->perPage($request))
        );
    }

    public function show(Request $request, string $slug): KbArticleResource
    {
        /** @var User $user */
        $user = $request->user();

        $query = KbArticle::query()->with('category')->where('slug', $slug);

        $this->scopeForReader($query, $user, $request);

        return KbArticleResource::full($query->firstOrFail());
    }

    /**
     * An agent reads the agent view of the KB; everyone else reads the portal
     * view. The same split the web UI makes, made once here so the two cannot
     * drift apart.
     *
     * @param  Builder<KbArticle>  $query
     */
    private function scopeForReader(Builder $query, User $user, Request $request): void
    {
        if ($user->isAgent()) {
            $query->visibleToAgent($user);

            // An agent may narrow to one language; by default they see all of
            // them, because an agent answering a Dutch customer in an English
            // console still needs the Dutch article.
            if ($locale = $request->string('locale')->toString()) {
                $query->where(fn ($scoped) => $scoped->whereNull('locale')->orWhere('locale', $locale));
            }

            return;
        }

        $query->visibleOnPortal($request->string('locale')->toString() ?: $user->locale);
    }
}
