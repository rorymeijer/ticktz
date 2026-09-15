<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Populates a recognisable demo instance: a Dutch municipal service desk with
 * agents, teams, customer organisations and requesters.
 *
 * Never run as part of DatabaseSeeder — this is opt-in through
 * `php artisan ticktz:demo`, which refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'ticktz-demo';

    /**
     * @var array<int, array{name: string, email: string, role: string, title?: string, team?: string}>
     */
    private const STAFF = [
        ['name' => 'Rianne Bakker', 'email' => 'rianne@ticktz.test', 'role' => Role::ADMIN, 'title' => 'Service desk manager', 'team' => 'servicedesk'],
        ['name' => 'Joost Vermeer', 'email' => 'joost@ticktz.test', 'role' => Role::AGENT, 'title' => 'Support engineer', 'team' => 'servicedesk'],
        ['name' => 'Aisha Yılmaz', 'email' => 'aisha@ticktz.test', 'role' => Role::AGENT, 'title' => 'Support engineer', 'team' => 'servicedesk'],
        ['name' => 'Bram de Wit', 'email' => 'bram@ticktz.test', 'role' => Role::AGENT, 'title' => 'Systems administrator', 'team' => 'infrastructure'],
        ['name' => 'Fenna Hoekstra', 'email' => 'fenna@ticktz.test', 'role' => Role::AGENT, 'title' => 'Application manager', 'team' => 'applications'],
    ];

    /**
     * @var array<int, array{name: string, slug: string, description: string, email: string}>
     */
    private const TEAMS = [
        ['name' => 'Service desk', 'slug' => 'servicedesk', 'description' => 'First line: intake, triage and quick fixes.', 'email' => 'servicedesk@ticktz.test'],
        ['name' => 'Infrastructure', 'slug' => 'infrastructure', 'description' => 'Networks, servers and workplace hardware.', 'email' => 'infra@ticktz.test'],
        ['name' => 'Applications', 'slug' => 'applications', 'description' => 'Case management, document systems and integrations.', 'email' => 'apps@ticktz.test'],
    ];

    /**
     * @var array<int, array{name: string, slug: string, domains: array<int, string>, shared: bool}>
     */
    private const ORGANIZATIONS = [
        ['name' => 'Gemeente Zandvliet', 'slug' => 'gemeente-zandvliet', 'domains' => ['zandvliet.test'], 'shared' => true],
        ['name' => 'Omgevingsdienst Noord', 'slug' => 'omgevingsdienst-noord', 'domains' => ['odnoord.test'], 'shared' => false],
        ['name' => 'Veiligheidsregio West', 'slug' => 'veiligheidsregio-west', 'domains' => ['vrwest.test'], 'shared' => false],
    ];

    /**
     * @var array<int, array{name: string, email: string, organization: string, title: string}>
     */
    private const REQUESTERS = [
        ['name' => 'Marieke Visser', 'email' => 'm.visser@zandvliet.test', 'organization' => 'gemeente-zandvliet', 'title' => 'Beleidsmedewerker'],
        ['name' => 'Ahmed Bouzid', 'email' => 'a.bouzid@zandvliet.test', 'organization' => 'gemeente-zandvliet', 'title' => 'Juridisch adviseur'],
        ['name' => 'Sanne Mulder', 'email' => 's.mulder@zandvliet.test', 'organization' => 'gemeente-zandvliet', 'title' => 'Communicatieadviseur'],
        ['name' => 'Pieter Dijkstra', 'email' => 'p.dijkstra@odnoord.test', 'organization' => 'omgevingsdienst-noord', 'title' => 'Vergunningverlener'],
        ['name' => 'Lotte Schouten', 'email' => 'l.schouten@vrwest.test', 'organization' => 'veiligheidsregio-west', 'title' => 'Meldkamercoördinator'],
    ];

    public function run(): void
    {
        $this->call([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class]);

        $teams = collect(self::TEAMS)->mapWithKeys(fn (array $team) => [
            $team['slug'] => Team::query()->updateOrCreate(['slug' => $team['slug']], [
                'name' => $team['name'],
                'description' => $team['description'],
                'email' => $team['email'],
                'is_active' => true,
            ]),
        ]);

        $organizations = collect(self::ORGANIZATIONS)->mapWithKeys(fn (array $organization) => [
            $organization['slug'] => Organization::query()->updateOrCreate(['slug' => $organization['slug']], [
                'name' => $organization['name'],
                'email_domains' => $organization['domains'],
                'contact_email' => 'info@'.$organization['domains'][0],
                'is_active' => true,
                'shared_ticket_visibility' => $organization['shared'],
            ]),
        ]);

        foreach (self::STAFF as $index => $person) {
            $user = $this->upsertUser($person['name'], $person['email'], [
                'job_title' => $person['title'] ?? null,
                'locale' => 'nl',
            ]);

            $user->syncRoleNames([$person['role'], Role::AGENT]);

            if (isset($person['team'])) {
                $team = $teams[$person['team']];
                $user->teams()->syncWithoutDetaching([
                    $team->getKey() => ['role' => $index === 0 ? 'lead' : 'member'],
                ]);
            }
        }

        foreach (self::REQUESTERS as $person) {
            $user = $this->upsertUser($person['name'], $person['email'], [
                'job_title' => $person['title'],
                'locale' => 'nl',
                'organization_id' => $organizations[$person['organization']]->getKey(),
            ]);

            $user->syncRoleNames([Role::REQUESTER]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertUser(string $name, string $email, array $attributes = []): User
    {
        return User::query()->updateOrCreate(['email' => $email], array_merge([
            'name' => $name,
            'username' => Str::slug($name, '.'),
            'password' => self::PASSWORD,
            'directory' => 'local',
            'is_active' => true,
        ], $attributes));
    }
}
