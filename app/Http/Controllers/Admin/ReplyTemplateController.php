<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReplyTemplate;
use App\Models\Team;
use App\Rules\RichText;
use App\Services\AuditLogger;
use App\Services\Tickets\TicketPlaceholders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Curating what the desk says when it says the same thing again.
 *
 * One list, edited in one place, used by the reply box and by the automation
 * rules. The screen shows both readers explicitly — a template says whether it
 * is a reply or a note, and which team it belongs to — because those two
 * facts are what decide where it turns up, and a template nobody can find is
 * the failure mode this feature has.
 */
class ReplyTemplateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('templates.manage');

        return Inertia::render('Admin/ReplyTemplates/Index', [
            'templates' => ReplyTemplate::query()
                ->with('team:id,name')
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (ReplyTemplate $template) => $template->toAdminArray())
                ->all(),
        ] + $this->options());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('templates.manage');

        $template = ReplyTemplate::query()->create(
            $this->validated($request, null) + ['created_by' => $request->user()?->getKey()],
        );

        $this->audit->created($template, "Created reply template {$template->name}");

        return back()->with('success', __('admin.reply_templates.created', ['name' => $template->name]));
    }

    public function update(Request $request, ReplyTemplate $replyTemplate): RedirectResponse
    {
        $this->authorize('templates.manage');

        $replyTemplate->fill($this->validated($request, $replyTemplate))->save();

        $this->audit->updated($replyTemplate, "Updated reply template {$replyTemplate->name}");

        return back()->with('success', __('admin.reply_templates.updated', ['name' => $replyTemplate->name]));
    }

    public function destroy(ReplyTemplate $replyTemplate): RedirectResponse
    {
        $this->authorize('templates.manage');

        // Automation rules reference a template by id. Deleting one leaves
        // those rules pointing at nothing, and the runner reports "no such
        // template" in the execution log rather than failing silently — see
        // ActionRunner::replyTemplate(). Switching a template off instead of
        // deleting it is the gentler move, and the screen says so.
        $this->audit->deleted($replyTemplate, "Deleted reply template {$replyTemplate->name}");
        $replyTemplate->delete();

        return back()->with('success', __('admin.reply_templates.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'locales' => array_keys((array) config('ticktz.locales', [])),
            // The cheat sheet, straight from the renderer. A token this screen
            // offers is a token the renderer knows, because it is the same list.
            'placeholders' => TicketPlaceholders::TOKENS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ReplyTemplate $template): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('reply_templates', 'slug')->ignore($template?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', new RichText],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'locale' => ['nullable', 'string', 'max:10', Rule::in(array_keys((array) config('ticktz.locales', [])))],
            'is_internal' => ['boolean'],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:65535'],
        ]);
    }
}
