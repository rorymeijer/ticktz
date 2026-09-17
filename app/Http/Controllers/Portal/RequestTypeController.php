<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Priority;
use App\Models\RequestType;
use App\Services\Portal\RequestSubmissionService;
use App\Services\Tickets\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rendering and accepting a request type's dynamic form.
 */
class RequestTypeController extends Controller
{
    public function __construct(
        private readonly RequestSubmissionService $submissions,
        private readonly AttachmentService $attachments,
    ) {}

    public function show(Request $request, RequestType $requestType): Response
    {
        $this->authorizeRequestType($request, $requestType);

        $requestType->load(['fields', 'category']);

        return Inertia::render('Portal/RequestForm', [
            'requestType' => $requestType->toPortalArray() + [
                'instructions' => $requestType->translatedInstructions(),
                'subject_template' => $requestType->subject_template,
                'allow_priority_choice' => $requestType->allow_priority_choice,
                'fields' => $requestType->formFields(),
            ],
            'priorities' => $requestType->allow_priority_choice
                ? Priority::query()->where('is_public', true)->orderBy('level')->get()
                    ->map(fn (Priority $priority) => $priority->toSummaryArray())->all()
                : [],
        ]);
    }

    public function store(Request $request, RequestType $requestType): RedirectResponse
    {
        $this->authorizeRequestType($request, $requestType);

        $ticket = $this->submissions->submit(
            $requestType,
            $request->all(),
            $request->user(),
        );

        if ($request->hasFile('attachments')) {
            $this->attachments->storeMany($ticket, $request->file('attachments'), $request->user());
        }

        return redirect()
            ->route('portal.requests.show', $ticket)
            ->with('success', __('portal.flash.submitted', ['key' => $ticket->key]));
    }

    /**
     * A request type that is not offered to this user must 404, not 403: the
     * existence of an internal request type is itself information.
     */
    private function authorizeRequestType(Request $request, RequestType $requestType): void
    {
        $available = RequestType::query()
            ->availableTo($request->user())
            ->whereKey($requestType->getKey())
            ->exists();

        abort_unless($available, 404);
    }
}
