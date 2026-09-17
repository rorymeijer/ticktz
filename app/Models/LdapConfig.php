<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directory (LDAP / Active Directory) connection, editable by administrators.
 *
 * The bind password is encrypted at rest with the application key. Nothing in
 * this model is ever shared with the browser verbatim — see toAdminArray().
 *
 * @property array<int, string> $hosts
 * @property array<string, string> $attribute_map
 * @property array<string, string>|null $group_role_map
 */
class LdapConfig extends Model
{
    use Auditable;

    protected $table = 'ldap_configs';

    protected $fillable = [
        'name', 'connection', 'is_enabled', 'hosts', 'port', 'encryption', 'timeout',
        'base_dn', 'bind_dn', 'bind_password', 'login_attribute', 'user_filter',
        'attribute_map', 'sync_groups', 'group_base_dn', 'group_filter',
        'group_member_attribute', 'group_role_map', 'default_role_id',
        'auto_provision', 'deactivate_missing',
    ];

    protected $hidden = ['bind_password'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sync_groups' => 'boolean',
            'auto_provision' => 'boolean',
            'deactivate_missing' => 'boolean',
            'hosts' => 'array',
            'attribute_map' => 'array',
            'group_role_map' => 'array',
            'bind_password' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Default attribute mapping for Active Directory. OpenLDAP installs
     * usually swap `samaccountname` for `uid` and `objectguid` for `entryuuid`.
     *
     * @return array<string, string>
     */
    public static function defaultAttributeMap(): array
    {
        return [
            'name' => 'cn',
            'email' => 'mail',
            'username' => 'samaccountname',
            'job_title' => 'title',
            'phone' => 'telephonenumber',
        ];
    }

    /**
     * The LdapRecord connection array this record describes.
     *
     * @return array<string, mixed>
     */
    public function toConnectionConfig(): array
    {
        return [
            'hosts' => $this->hosts,
            'port' => $this->port,
            'base_dn' => $this->base_dn,
            'username' => $this->bind_dn,
            'password' => $this->bind_password,
            'timeout' => $this->timeout,
            // LdapRecord v4 vocabulary: `use_tls` means LDAPS (ldaps://) and
            // `use_starttls` upgrades a plain connection in place.
            'use_tls' => $this->encryption === 'ssl',
            'use_starttls' => $this->encryption === 'tls',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    /**
     * Admin-facing representation. The bind password is reduced to a boolean
     * so it can never round-trip through the browser.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'connection' => $this->connection,
            'is_enabled' => $this->is_enabled,
            'hosts' => $this->hosts,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'timeout' => $this->timeout,
            'base_dn' => $this->base_dn,
            'bind_dn' => $this->bind_dn,
            'has_bind_password' => filled($this->getRawOriginal('bind_password')),
            'login_attribute' => $this->login_attribute,
            'user_filter' => $this->user_filter,
            'attribute_map' => $this->attribute_map,
            'sync_groups' => $this->sync_groups,
            'group_base_dn' => $this->group_base_dn,
            'group_filter' => $this->group_filter,
            'group_member_attribute' => $this->group_member_attribute,
            'group_role_map' => $this->group_role_map ?? [],
            'default_role_id' => $this->default_role_id,
            'auto_provision' => $this->auto_provision,
            'deactivate_missing' => $this->deactivate_missing,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_test_result' => $this->last_test_result,
        ];
    }
}
