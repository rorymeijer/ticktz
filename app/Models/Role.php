<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions.
 *
 * `is_super` short-circuits every permission check (see AuthServiceProvider);
 * `is_system` marks the three roles Ticktz cannot run without, which may be
 * re-permissioned but never renamed or deleted.
 *
 * @property string $name
 * @property string $display_name
 * @property string $scope
 * @property bool $is_super
 * @property bool $is_system
 * @property bool $is_default
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use Auditable, HasFactory;

    public const ADMIN = 'admin';

    public const AGENT = 'agent';

    public const REQUESTER = 'requester';

    protected $fillable = [
        'name', 'display_name', 'description', 'scope',
        'is_super', 'is_system', 'is_default', 'position',
    ];

    protected function casts(): array
    {
        return [
            'is_super' => 'boolean',
            'is_system' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Replace the role's permissions with the given slugs, ignoring anything
     * outside the catalogue.
     *
     * @param  array<int, string>  $names
     */
    public function syncPermissionNames(array $names): void
    {
        $ids = Permission::query()
            ->whereIn('name', $names)
            ->pluck('id')
            ->all();

        $this->permissions()->sync($ids);
    }

    public function isProtected(): bool
    {
        return $this->is_system;
    }
}
