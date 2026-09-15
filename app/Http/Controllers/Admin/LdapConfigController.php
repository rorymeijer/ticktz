<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LdapConfig;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\Ldap\LdapManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Directory (LDAP / Active Directory) configuration.
 *
 * The bind password is write-only: it is never sent to the browser, and an
 * empty field on update means "keep the stored password".
 */
class LdapConfigController extends Controller
{
    public function __construct(
        private readonly LdapManager $manager,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): Response
    {
        $this->authorize('directory.manage');

        return Inertia::render('Admin/Directories/Index', [
            'directories' => LdapConfig::query()
                ->orderBy('id')
                ->get()
                ->map(fn (LdapConfig $config) => $config->toAdminArray())
                ->all(),
            'extensionAvailable' => LdapManager::extensionAvailable(),
            'roles' => Role::query()->orderBy('position')->get(['id', 'name', 'display_name']),
            'defaultAttributeMap' => LdapConfig::defaultAttributeMap(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('directory.manage');

        $data = $this->validateDirectory($request, null);

        $config = LdapConfig::query()->create($data);

        $this->audit->created($config, "Created directory connection {$config->name}");

        return back()->with('success', __('admin.directories.created', ['name' => $config->name]));
    }

    public function update(Request $request, LdapConfig $directory): RedirectResponse
    {
        $this->authorize('directory.manage');

        $data = $this->validateDirectory($request, $directory);

        if (blank($data['bind_password'] ?? null)) {
            unset($data['bind_password']);
        }

        $directory->fill($data)->save();

        $this->audit->updated($directory, "Updated directory connection {$directory->name}");

        return back()->with('success', __('admin.directories.updated', ['name' => $directory->name]));
    }

    public function destroy(LdapConfig $directory): RedirectResponse
    {
        $this->authorize('directory.manage');

        $this->audit->deleted($directory, "Deleted directory connection {$directory->name}");
        $directory->delete();

        return back()->with('success', __('admin.directories.deleted'));
    }

    /**
     * Bind with the stored service account and report the result. Nothing is
     * synchronised — this only answers "can Ticktz reach the directory?".
     */
    public function test(LdapConfig $directory): RedirectResponse
    {
        $this->authorize('directory.manage');

        if (! LdapManager::extensionAvailable()) {
            return back()->with('error', __('admin.directories.extension_missing'));
        }

        try {
            $connection = $this->manager->connection($directory);
            $connection->connect();

            $count = $connection->query()->setDn($directory->base_dn)->limit(1)->get();

            $result = __('admin.directories.test_ok', ['count' => is_countable($count) ? count($count) : 0]);
            $flash = ['success' => $result];
        } catch (Throwable $e) {
            $result = $e->getMessage();
            $flash = ['error' => __('admin.directories.test_failed', ['message' => $result])];
        }

        $directory->forceFill([
            'last_tested_at' => now(),
            'last_test_result' => mb_substr($result, 0, 255),
        ])->save();

        $this->audit->log($directory, 'directory.tested', "Tested directory connection {$directory->name}");

        return back()->with($flash);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDirectory(Request $request, ?LdapConfig $directory): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'connection' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('ldap_configs', 'connection')->ignore($directory?->getKey()),
            ],
            'is_enabled' => ['boolean'],
            'hosts' => ['required', 'array', 'min:1'],
            'hosts.*' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(['none', 'ssl', 'tls'])],
            'timeout' => ['required', 'integer', 'min:1', 'max:60'],
            'base_dn' => ['required', 'string', 'max:512'],
            'bind_dn' => ['nullable', 'string', 'max:512'],
            'bind_password' => ['nullable', 'string', 'max:255'],
            'login_attribute' => ['required', 'string', 'max:64'],
            'user_filter' => ['nullable', 'string', 'max:512'],
            'attribute_map' => ['required', 'array'],
            'attribute_map.*' => ['nullable', 'string', 'max:64'],
            'sync_groups' => ['boolean'],
            'group_base_dn' => ['nullable', 'string', 'max:512'],
            'group_filter' => ['nullable', 'string', 'max:512'],
            'group_member_attribute' => ['required', 'string', 'max:64'],
            'group_role_map' => ['array'],
            'group_role_map.*' => ['string', Rule::exists('roles', 'name')],
            'default_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'auto_provision' => ['boolean'],
            'deactivate_missing' => ['boolean'],
        ]);

        $validated['hosts'] = array_values(array_filter(array_map('trim', $validated['hosts'])));
        $validated['attribute_map'] = array_filter(
            $validated['attribute_map'],
            static fn ($value) => filled($value)
        );

        return $validated;
    }
}
