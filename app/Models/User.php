<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person with access to Ticktz: an administrator, an agent or a requester.
 *
 * Accounts are never hard-deleted from the UI — tickets, comments and audit
 * entries keep referring to them. Deactivation (`is_active = false`) removes
 * every permission and blocks sign-in; soft deletion additionally hides the
 * account from pickers.
 *
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property string|null $locale
 * @property string $directory
 * @property string|null $ldap_dn
 * @property bool $is_active
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'locale',
        'organization_id',
        'directory',
        'ldap_dn',
        'ldap_guid',
        'ldap_synced_at',
        'job_title',
        'phone',
        'timezone',
        'signature',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'ldap_dn',
        'ldap_guid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ldap_synced_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Users who can work tickets — i.e. hold a role with agent or admin scope.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAgents(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('scope', ['agent', 'admin']));
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('username', 'like', $like);
        });
    }

    // ---------------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------------

    /**
     * Deactivated accounts cannot sign in, even with valid credentials.
     */
    public function isDirectoryManaged(): bool
    {
        return $this->directory === 'ldap';
    }

    /**
     * @return array<int, int>
     */
    public function teamIds(): array
    {
        return $this->relationLoaded('teams')
            ? $this->teams->pluck('id')->all()
            : $this->teams()->pluck('teams.id')->all();
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)),
            array_slice(array_filter($parts), 0, 2)
        );

        return $letters === [] ? '?' : implode('', $letters);
    }

    /**
     * Deterministic avatar tint so the same person always looks the same
     * without storing an extra column or uploading images.
     */
    public function avatarColor(): string
    {
        $palette = ['#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0284c7'];

        return $palette[crc32((string) $this->getKey()) % count($palette)];
    }

    /**
     * The shape shared with the React front-end. Kept explicit so we never
     * leak a column (password hash, LDAP DN, ...) by accident.
     *
     * @return array<string, mixed>
     */
    public function toInertiaArray(): array
    {
        $this->loadMissing('roles.permissions', 'teams');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale ?? config('app.locale'),
            'initials' => $this->initials(),
            'avatar_color' => $this->avatarColor(),
            'is_admin' => $this->isAdmin(),
            'is_agent' => $this->isAgent(),
            'is_requester' => $this->isRequester(),
            'roles' => $this->roles->pluck('name')->all(),
            'permissions' => $this->permissionNames(),
            'team_ids' => $this->teamIds(),
            'organization_id' => $this->organization_id,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
        ];
    }

    /**
     * Compact shape used inside ticket payloads, pickers and mention lists.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'initials' => $this->initials(),
            'avatar_color' => $this->avatarColor(),
        ];
    }
}
