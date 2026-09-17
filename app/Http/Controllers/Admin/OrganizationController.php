<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->withCount('users')
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderBy('name')
            ->paginate(config('ticktz.per_page.admin'))
            ->withQueryString();

        return Inertia::render('Admin/Organizations/Index', [
            'organizations' => $organizations,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Organization::class);

        return Inertia::render('Admin/Organizations/Form', ['organization' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Organization::class);

        $organization = Organization::query()->create($this->validateOrganization($request, null));

        $this->audit->created($organization, "Created organisation {$organization->name}");

        return redirect()->route('admin.organizations.index')
            ->with('success', __('admin.organizations.created', ['name' => $organization->name]));
    }

    public function edit(Organization $organization): Response
    {
        $this->authorize('update', $organization);

        return Inertia::render('Admin/Organizations/Form', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'description' => $organization->description,
                'email_domains' => $organization->email_domains ?? [],
                'contact_email' => $organization->contact_email,
                'phone' => $organization->phone,
                'is_active' => $organization->is_active,
                'shared_ticket_visibility' => $organization->shared_ticket_visibility,
            ],
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('update', $organization);

        $organization->fill($this->validateOrganization($request, $organization))->save();

        $this->audit->updated($organization, "Updated organisation {$organization->name}");

        return redirect()->route('admin.organizations.index')
            ->with('success', __('admin.organizations.updated', ['name' => $organization->name]));
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        $this->authorize('delete', $organization);

        $this->audit->deleted($organization, "Deleted organisation {$organization->name}");
        $organization->delete();

        return redirect()->route('admin.organizations.index')
            ->with('success', __('admin.organizations.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrganization(Request $request, ?Organization $organization): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', Rule::unique('organizations', 'slug')->ignore($organization?->getKey())],
            'description' => ['nullable', 'string', 'max:5000'],
            'email_domains' => ['array'],
            'email_domains.*' => ['string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'shared_ticket_visibility' => ['boolean'],
        ]);

        $validated['email_domains'] = array_values(array_unique(array_map(
            'mb_strtolower',
            $validated['email_domains'] ?? []
        )));

        return $validated;
    }
}
