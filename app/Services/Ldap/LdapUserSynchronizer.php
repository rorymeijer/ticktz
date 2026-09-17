<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Models\LdapConfig;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Turns a directory entry into a Ticktz account.
 *
 * Matching order is deliberate: the immutable GUID first (survives renames and
 * OU moves), then the distinguished name, then the e-mail address — which is
 * how an existing local account gets "adopted" by the directory rather than
 * duplicated.
 */
class LdapUserSynchronizer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $entry  raw LDAP attributes, lower-cased keys
     */
    public function sync(LdapConfig $config, array $entry): ?User
    {
        // firstValue() rather than a cast: a directory may hand the DN back
        // as a one-element array like any other attribute.
        $dn = (string) ($this->firstValue($entry, 'dn') ?? '');
        $guid = $this->guidFrom($entry);
        $attributes = $this->mapAttributes($config, $entry);

        if (($attributes['email'] ?? null) === null && ($attributes['username'] ?? null) === null) {
            // Without an e-mail address or username there is nothing to key the
            // account on, and a service desk account without an address cannot
            // receive notifications.
            return null;
        }

        $user = $this->locate($guid, $dn, $attributes);

        if (! $user && ! $config->auto_provision) {
            return null;
        }

        $isNew = $user === null;
        $user ??= new User;

        $user->fill(array_filter($attributes, static fn ($value) => $value !== null && $value !== ''));
        $user->directory = 'ldap';
        $user->ldap_dn = $dn;
        $user->ldap_guid = $guid ?: $user->ldap_guid;
        $user->ldap_synced_at = now();
        $user->is_active = true;

        if ($isNew) {
            $user->locale ??= config('app.locale');
            $user->organization_id ??= Organization::matchingEmail((string) $user->email)?->getKey();
        }

        $user->save();

        $this->applyRoles($config, $user, $entry, $isNew);

        $this->audit->as('system', 'Directory sync')->log(
            $user,
            $isNew ? 'ldap.provisioned' : 'ldap.synced',
            $isNew
                ? "Account provisioned from directory [{$config->name}]"
                : "Account synchronised from directory [{$config->name}]",
        );

        return $user->fresh(['roles.permissions', 'teams']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function locate(?string $guid, string $dn, array $attributes): ?User
    {
        $query = User::query()->withTrashed();

        if ($guid) {
            $found = (clone $query)->where('ldap_guid', $guid)->first();

            if ($found) {
                return $found;
            }
        }

        if ($dn !== '') {
            $found = (clone $query)->where('ldap_dn', $dn)->first();

            if ($found) {
                return $found;
            }
        }

        if (! empty($attributes['email'])) {
            $found = (clone $query)->where('email', $attributes['email'])->first();

            if ($found) {
                return $found;
            }
        }

        if (! empty($attributes['username'])) {
            return (clone $query)->where('username', $attributes['username'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, string|null>
     */
    private function mapAttributes(LdapConfig $config, array $entry): array
    {
        $mapped = [];

        foreach ($config->attribute_map as $column => $ldapAttribute) {
            $value = $this->firstValue($entry, $ldapAttribute);

            $mapped[$column] = $column === 'email' && is_string($value)
                ? mb_strtolower($value)
                : $value;
        }

        // A directory entry with no cn still needs a display name.
        $mapped['name'] = $mapped['name'] ?? $mapped['username'] ?? $mapped['email'] ?? null;

        return $mapped;
    }

    /**
     * Map directory groups onto Ticktz roles.
     *
     * A user who matches no mapped group falls back to the directory's default
     * role, and failing that to the role flagged `is_default`. Role assignment
     * is recomputed on every sign-in so revoking a group in AD revokes access
     * here too.
     *
     * @param  array<string, mixed>  $entry
     */
    private function applyRoles(LdapConfig $config, User $user, array $entry, bool $isNew): void
    {
        if (! $config->sync_groups) {
            if ($isNew) {
                $this->assignFallbackRole($config, $user);
            }

            return;
        }

        $groups = $this->groupsOf($config, $entry);
        $map = array_change_key_case($config->group_role_map ?? [], CASE_LOWER);

        $roles = [];

        foreach ($groups as $group) {
            foreach ([mb_strtolower($group), mb_strtolower($this->commonNameOf($group))] as $candidate) {
                if (isset($map[$candidate])) {
                    $roles[] = $map[$candidate];
                }
            }
        }

        $roles = array_values(array_unique($roles));

        if ($roles !== []) {
            $user->syncRoleNames($roles);

            return;
        }

        // Nobody matched a mapped group. With a map configured the directory
        // is authoritative, so somebody who has been taken out of the service
        // desk group in AD drops back to the default role here on their next
        // sign-in — keeping the role they used to have would mean access
        // survives being revoked upstream.
        //
        // With no map configured there is nothing to recompute from: group
        // sync was switched on before the mapping was filled in, and flattening
        // every account to the default role at that moment would lock the
        // administrator out of the screen they were about to use.
        $map === []
            ? $this->assignFallbackRole($config, $user)
            : $this->resetToFallbackRole($config, $user);
    }

    /** Give the user the default role, but only if they have none at all. */
    private function assignFallbackRole(LdapConfig $config, User $user): void
    {
        if ($user->roles()->exists()) {
            return;
        }

        $role = $this->fallbackRole($config);

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->getKey()]);
        }
    }

    /** Replace whatever the user has with the default role. */
    private function resetToFallbackRole(LdapConfig $config, User $user): void
    {
        $role = $this->fallbackRole($config);

        // syncRoleNames() rather than the pivot: it also drops the resolved
        // permission cache, so the demotion takes effect within this request.
        $user->syncRoleNames($role ? [$role->name] : []);
    }

    private function fallbackRole(LdapConfig $config): ?Role
    {
        return $config->default_role_id
            ? Role::query()->find($config->default_role_id)
            : Role::query()->where('is_default', true)->first();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function groupsOf(LdapConfig $config, array $entry): array
    {
        $attribute = mb_strtolower($config->group_member_attribute ?: 'memberof');
        $value = $entry[$attribute] ?? [];

        if (is_string($value)) {
            return [$value];
        }

        return array_values(array_filter(
            Arr::except((array) $value, ['count']),
            static fn ($item) => is_string($item),
        ));
    }

    /**
     * "CN=Servicedesk,OU=Groups,DC=example,DC=org" => "Servicedesk"
     */
    private function commonNameOf(string $dn): string
    {
        if (! Str::contains($dn, '=')) {
            return $dn;
        }

        $first = explode(',', $dn)[0];

        return trim(Str::after($first, '='));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function firstValue(array $entry, string $attribute): ?string
    {
        $value = $entry[mb_strtolower($attribute)] ?? null;

        if (is_array($value)) {
            $value = Arr::first(Arr::except($value, ['count']));
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function guidFrom(array $entry): ?string
    {
        foreach (['objectguid', 'entryuuid', 'nsuniqueid', 'guid'] as $attribute) {
            $value = $this->firstValue($entry, $attribute);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
