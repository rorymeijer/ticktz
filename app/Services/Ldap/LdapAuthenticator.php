<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Models\LdapConfig;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\Attributes\Guid;
use Throwable;

/**
 * Authenticates a person against every enabled directory.
 *
 * The flow per directory is the standard two-bind dance:
 *   1. bind with the service account and search for the login attribute;
 *   2. re-bind as the entry that was found, using the password the person
 *      typed. Only that second bind proves the password.
 *
 * A failure in one directory never aborts the loop — an instance may have a
 * staff AD and a contractor OpenLDAP configured side by side.
 */
class LdapAuthenticator
{
    public function __construct(
        private readonly LdapManager $manager,
        private readonly LdapUserSynchronizer $synchronizer,
    ) {}

    public function attempt(string $login, string $password): ?User
    {
        if ($password === '') {
            return null;
        }

        if (! LdapManager::extensionAvailable()) {
            // Configured directories are unusable without ext-ldap. Say so
            // once per attempt rather than throwing on every sign-in.
            if ($this->manager->enabledConfigs()->isNotEmpty()) {
                Log::warning('A directory is configured but the PHP LDAP extension is not installed.');
            }

            return null;
        }

        foreach ($this->manager->enabledConfigs() as $config) {
            try {
                $user = $this->attemptAgainst($config, $login, $password);

                if ($user) {
                    return $user;
                }
            } catch (Throwable $e) {
                // A directory being unreachable must not block local sign-in.
                Log::warning('LDAP authentication failed for directory [{directory}]: {message}', [
                    'directory' => $config->name,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function attemptAgainst(LdapConfig $config, string $login, string $password): ?User
    {
        $connection = $this->manager->connection($config);
        $connection->connect();

        $entry = $this->findEntry($connection, $config, $login);

        if ($entry === null) {
            return null;
        }

        $dn = (string) ($entry['dn'] ?? '');

        if ($dn === '' || ! $connection->auth()->attempt($dn, $password)) {
            return null;
        }

        return $this->synchronizer->sync($config, $this->normalise($entry));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEntry(object $connection, LdapConfig $config, string $login): ?array
    {
        $attribute = $config->login_attribute ?: 'samaccountname';

        // Someone who types their full e-mail address should still be found by
        // a directory keyed on sAMAccountName.
        $candidates = array_unique(array_filter([
            $login,
            str_contains($login, '@') ? strstr($login, '@', true) : null,
        ]));

        foreach ($candidates as $candidate) {
            $query = $connection->query()
                ->setDn($config->base_dn)
                ->where($attribute, '=', $candidate);

            if (filled($config->user_filter)) {
                $query->rawFilter($config->user_filter);
            }

            $entry = $query->first();

            if (is_array($entry)) {
                return $entry;
            }
        }

        // Fall back to the mail attribute for directories where people sign in
        // with their address.
        if (str_contains($login, '@') && $attribute !== 'mail') {
            $entry = $connection->query()->setDn($config->base_dn)->where('mail', '=', $login)->first();

            return is_array($entry) ? $entry : null;
        }

        return null;
    }

    /**
     * Lower-case the attribute keys and decode the binary objectGUID that
     * Active Directory returns, so downstream code sees one predictable shape.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalise(array $entry): array
    {
        $normalised = [];

        foreach ($entry as $key => $value) {
            $normalised[is_string($key) ? mb_strtolower($key) : $key] = $value;
        }

        if (isset($normalised['objectguid'])) {
            $raw = is_array($normalised['objectguid'])
                ? Arr::first(Arr::except($normalised['objectguid'], ['count']))
                : $normalised['objectguid'];

            try {
                $normalised['objectguid'] = (new Guid($raw))->getValue();
            } catch (Throwable) {
                $normalised['objectguid'] = is_string($raw) ? $raw : null;
            }
        }

        return $normalised;
    }
}
