<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Syncs the code-defined permission catalogue into the database.
 *
 * Idempotent and safe to re-run on every deploy: new permissions are inserted,
 * removed ones are deleted (cascading out of role_permissions).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = PermissionCatalog::all();

        foreach ($catalog as $name) {
            Permission::query()->updateOrCreate(
                ['name' => $name],
                ['group' => PermissionCatalog::groupOf($name)],
            );
        }

        Permission::query()->whereNotIn('name', $catalog)->delete();
    }
}
