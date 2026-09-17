<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('audit.view');

        $entries = AuditLogEntry::query()
            ->with('user:id,name,email')
            ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')->toString()))
            ->when($request->filled('actor_type'), fn ($query) => $query->where('actor_type', $request->string('actor_type')->toString()))
            ->when($request->filled('subject'), fn ($query) => $query->where('auditable_type', 'like', '%'.$request->string('subject')->toString().'%'))
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<=', $request->date('to')))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLogEntry $entry) => $entry->toDisplayArray() + [
                'subject_type' => class_basename($entry->auditable_type),
                'subject_id' => $entry->auditable_id,
            ]);

        return Inertia::render('Admin/AuditLog/Index', [
            'entries' => $entries,
            'filters' => $request->only('event', 'actor_type', 'subject', 'user_id', 'from', 'to'),
            'events' => AuditLogEntry::query()
                ->distinct()
                ->orderBy('event')
                ->limit(100)
                ->pluck('event'),
        ]);
    }
}
