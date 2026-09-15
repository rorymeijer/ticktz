<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Models\LdapConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use LdapRecord\Connection;
use LdapRecord\Container;
use Throwable;

/**
 * Registers the directory connections Ticktz knows about with LdapRecord's
 * container.
 *
 * Connections come from the `ldap_configs` table so administrators can manage
 * them from the UI; a connection configured purely through `.env`
 * (LDAP_ENABLED=true) is registered as well, which keeps
 * configuration-as-code deployments working.
 */
class LdapManager
{
    private bool $registered = false;

    /**
     * True when the host PHP can talk LDAP at all. Ticktz runs perfectly well
     * without ext-ldap — directory features simply stay unavailable.
     */
    public static function extensionAvailable(): bool
    {
        return function_exists('ldap_connect');
    }

    /**
     * Every enabled directory, ordered so the database-managed ones win over
     * the `.env` fallback of the same name.
     *
     * @return Collection<int, LdapConfig>
     */
    public function enabledConfigs(): Collection
    {
        if (! Schema::hasTable('ldap_configs')) {
            return collect();
        }

        $configs = LdapConfig::query()
            ->where('is_enabled', true)
            ->orderBy('id')
            ->get();

        if ($configs->isEmpty() && config('ldap.enabled')) {
            $configs = collect([$this->configFromEnvironment()]);
        }

        return $configs;
    }

    public function isEnabled(): bool
    {
        return self::extensionAvailable() && $this->enabledConfigs()->isNotEmpty();
    }

    /**
     * Push every enabled directory into LdapRecord's container. Idempotent;
     * calling it again re-reads the database.
     */
    public function register(bool $force = false): void
    {
        if ($this->registered && ! $force) {
            return;
        }

        foreach ($this->enabledConfigs() as $config) {
            try {
                Container::addConnection(new Connection($config->toConnectionConfig()), $config->connection);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->registered = true;
    }

    /**
     * Resolve the live connection for a directory, registering it on demand.
     */
    public function connection(LdapConfig $config): Connection
    {
        try {
            return Container::getConnection($config->connection);
        } catch (Throwable) {
            $connection = new Connection($config->toConnectionConfig());
            Container::addConnection($connection, $config->connection);

            return $connection;
        }
    }

    /**
     * An unsaved LdapConfig describing the `.env` connection, used when no
     * directory has been configured through the UI.
     */
    public function configFromEnvironment(): LdapConfig
    {
        $connection = (string) config('ldap.default', 'default');
        $settings = config("ldap.connections.{$connection}", []);

        return new LdapConfig([
            'name' => 'Environment ('.$connection.')',
            'connection' => $connection,
            'is_enabled' => true,
            'hosts' => (array) ($settings['hosts'] ?? []),
            'port' => (int) ($settings['port'] ?? 389),
            'encryption' => ($settings['use_tls'] ?? false) ? 'ssl' : (($settings['use_starttls'] ?? false) ? 'tls' : 'none'),
            'timeout' => (int) ($settings['timeout'] ?? 5),
            'base_dn' => (string) ($settings['base_dn'] ?? ''),
            'bind_dn' => $settings['username'] ?? null,
            'bind_password' => $settings['password'] ?? null,
            'login_attribute' => (string) env('LDAP_LOGIN_ATTRIBUTE', 'samaccountname'),
            'attribute_map' => LdapConfig::defaultAttributeMap(),
            'sync_groups' => (bool) env('LDAP_SYNC_GROUPS', true),
            'group_member_attribute' => (string) env('LDAP_GROUP_MEMBER_ATTRIBUTE', 'memberof'),
            'group_role_map' => [],
            'auto_provision' => (bool) env('LDAP_AUTO_PROVISION', true),
        ]);
    }
}
