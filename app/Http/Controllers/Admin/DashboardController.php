<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\LdapConfig;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration landing page: a health summary plus shortcuts into every
 * configuration area.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('Admin/Index', [
            'stats' => [
                'users' => User::query()->count(),
                'active_users' => User::query()->where('is_active', true)->count(),
                'agents' => User::query()->active()->agents()->count(),
                'teams' => Team::query()->count(),
                'organizations' => Organization::query()->count(),
                'roles' => Role::query()->count(),
                'directories' => LdapConfig::query()->where('is_enabled', true)->count(),
            ],
            'recentActivity' => AuditLogEntry::query()
                ->with('user:id,name,email')
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(fn (AuditLogEntry $entry) => $entry->toDisplayArray() + [
                    'subject_type' => class_basename($entry->auditable_type),
                ])
                ->all(),
        ]);
    }
}
