<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Label;
use App\Models\Organization;
use App\Models\Priority;
use App\Models\Role;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Tickets\TicketService;
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
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingsSeeder::class,
            TicketWorkflowSeeder::class,
        ]);

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

        $this->seedLabels();
        $this->seedTickets();
    }

    private function seedLabels(): void
    {
        $labels = [
            ['name' => 'Hardware', 'slug' => 'hardware', 'color' => '#0891b2'],
            ['name' => 'Account', 'slug' => 'account', 'color' => '#7c3aed'],
            ['name' => 'Netwerk', 'slug' => 'netwerk', 'color' => '#059669'],
            ['name' => 'Zaaksysteem', 'slug' => 'zaaksysteem', 'color' => '#d97706'],
            ['name' => 'Wet open overheid', 'slug' => 'woo', 'color' => '#dc2626'],
        ];

        foreach ($labels as $label) {
            Label::query()->updateOrCreate(['slug' => $label['slug']], $label);
        }
    }

    /**
     * A believable working day: a handful of tickets in different states, with
     * conversations, so every screen has something real to show.
     *
     * @var array<int, array{subject: string, description: string, requester: string, assignee: string|null, priority: string, status: string, labels: array<int, string>, team: string|null, replies: array<int, array{by: string, body: string, internal?: bool}>}>
     */
    private function ticketBlueprints(): array
    {
        return [
            [
                'subject' => 'Kan niet inloggen op het zaaksysteem',
                'description' => "Sinds vanochtend krijg ik na het invoeren van mijn wachtwoord de melding “ongeldige sessie”.\n\nIk heb al geprobeerd:\n- opnieuw opstarten\n- een andere browser\n- via de webmail inloggen (dat werkt wel)",
                'requester' => 'm.visser@zandvliet.test',
                'assignee' => 'joost@ticktz.test',
                'priority' => 'high',
                'status' => 'open',
                'labels' => ['account', 'zaaksysteem'],
                'team' => 'applications',
                'replies' => [
                    ['by' => 'joost@ticktz.test', 'body' => 'Dank voor je melding. Ik zie in de logs dat je account op twee werkplekken tegelijk actief is. Ik reset de sessie, kun je het over vijf minuten opnieuw proberen?'],
                    ['by' => 'joost@ticktz.test', 'body' => 'Sessietabel opgeschoond, rij-id 44821. Als dit vaker gebeurt moeten we de sessie-timeout van het zaaksysteem nakijken.', 'internal' => true],
                    ['by' => 'm.visser@zandvliet.test', 'body' => 'Het werkt weer, dank je wel!'],
                ],
            ],
            [
                'subject' => 'Nieuwe laptop voor collega per 1 oktober',
                'description' => "Per 1 oktober start er een nieuwe collega op de afdeling Vergunningen.\n\nGraag een standaard werkplek: laptop, docking station en twee schermen.",
                'requester' => 'p.dijkstra@odnoord.test',
                'assignee' => 'bram@ticktz.test',
                'priority' => 'normal',
                'status' => 'waiting-for-third-party',
                'labels' => ['hardware'],
                'team' => 'infrastructure',
                'replies' => [
                    ['by' => 'bram@ticktz.test', 'body' => 'Bestelling is geplaatst bij de leverancier. Verwachte levering is 26 september; ik houd je op de hoogte.'],
                    ['by' => 'bram@ticktz.test', 'body' => 'PO 2026-0918 bij Centralpoint. Levertijd kan uitlopen door chiptekort.', 'internal' => true],
                ],
            ],
            [
                'subject' => 'Wifi valt weg in vergaderruimte 2.14',
                'description' => 'Tijdens overleggen verliezen laptops steeds de verbinding. Vooral als er meer dan acht mensen zitten.',
                'requester' => 'a.bouzid@zandvliet.test',
                'assignee' => 'bram@ticktz.test',
                'priority' => 'normal',
                'status' => 'open',
                'labels' => ['netwerk'],
                'team' => 'infrastructure',
                'replies' => [
                    ['by' => 'bram@ticktz.test', 'body' => 'Het access point in 2.14 haalt zijn maximum aantal clients. Ik plan een tweede AP in; dat kan pas in het onderhoudsvenster van zaterdag.', 'internal' => true],
                ],
            ],
            [
                'subject' => 'Woo-verzoek: export van alle besluiten 2025',
                'description' => 'Voor de afhandeling van een Woo-verzoek heb ik een export nodig van alle besluiten uit 2025, inclusief bijlagen.',
                'requester' => 'a.bouzid@zandvliet.test',
                'assignee' => 'fenna@ticktz.test',
                'priority' => 'urgent',
                'status' => 'waiting-for-requester',
                'labels' => ['zaaksysteem', 'woo'],
                'team' => 'applications',
                'replies' => [
                    ['by' => 'fenna@ticktz.test', 'body' => 'Ik kan de export maken. Wil je alleen de genomen besluiten, of ook de ingetrokken aanvragen? Dat scheelt ongeveer 1.200 dossiers.'],
                ],
            ],
            [
                'subject' => 'Telefoon meldkamer neemt niet op na doorschakeling',
                'description' => 'Doorgeschakelde gesprekken vanuit de centrale komen niet aan op toestel 2201.',
                'requester' => 'l.schouten@vrwest.test',
                'assignee' => 'aisha@ticktz.test',
                'priority' => 'urgent',
                'status' => 'resolved',
                'labels' => ['netwerk'],
                'team' => 'infrastructure',
                'replies' => [
                    ['by' => 'aisha@ticktz.test', 'body' => 'De doorschakelregel stond nog op het oude toestelnummer. Aangepast en getest met de centrale — gesprekken komen weer binnen.'],
                ],
            ],
            [
                'subject' => 'Verzoek: extra mailbox voor team Handhaving',
                'description' => 'Graag een gedeelde mailbox handhaving@zandvliet.test waar vier collega\'s bij kunnen.',
                'requester' => 's.mulder@zandvliet.test',
                'assignee' => null,
                'priority' => 'low',
                'status' => 'new',
                'labels' => ['account'],
                'team' => null,
                'replies' => [],
            ],
            [
                'subject' => 'Printer 3e etage geeft steeds papierstoring',
                'description' => 'De multifunctional bij de koffiecorner loopt vast bij dubbelzijdig printen.',
                'requester' => 'm.visser@zandvliet.test',
                'assignee' => null,
                'priority' => 'low',
                'status' => 'new',
                'labels' => ['hardware'],
                'team' => 'servicedesk',
                'replies' => [],
            ],
            [
                'subject' => 'Zaaksysteem is traag bij het openen van grote dossiers',
                'description' => 'Dossiers met meer dan honderd documenten laden soms wel dertig seconden.',
                'requester' => 'p.dijkstra@odnoord.test',
                'assignee' => 'fenna@ticktz.test',
                'priority' => 'high',
                'status' => 'open',
                'labels' => ['zaaksysteem'],
                'team' => 'applications',
                'replies' => [
                    ['by' => 'fenna@ticktz.test', 'body' => 'We zien dit ook in de monitoring. De leverancier heeft een index-fix aangekondigd voor release 8.4.'],
                    ['by' => 'p.dijkstra@odnoord.test', 'body' => 'Is er een inschatting wanneer die release er is?'],
                ],
            ],
        ];
    }

    private function seedTickets(): void
    {
        // Idempotent: the demo command may be re-run on an existing instance.
        if (Ticket::query()->exists()) {
            return;
        }

        $service = app(TicketService::class);
        $users = User::query()->get()->keyBy('email');
        $teams = Team::query()->get()->keyBy('slug');
        $labels = Label::query()->get()->keyBy('slug');

        foreach ($this->ticketBlueprints() as $index => $blueprint) {
            $requester = $users[$blueprint['requester']];
            $assignee = $blueprint['assignee'] ? $users[$blueprint['assignee']] : null;
            $creator = $assignee ?? $users['rianne@ticktz.test'];

            $ticket = $service->create([
                'subject' => $blueprint['subject'],
                'description' => $blueprint['description'],
                'requester_id' => $requester->getKey(),
                'priority_id' => Priority::query()->where('slug', $blueprint['priority'])->value('id'),
                'team_id' => $blueprint['team'] ? $teams[$blueprint['team']]->getKey() : null,
                'label_ids' => collect($blueprint['labels'])->map(fn (string $slug) => $labels[$slug]->getKey())->all(),
                'source' => $index % 3 === 0 ? 'portal' : 'email',
            ], $creator);

            // Spread creation times over the past fortnight so the lists and
            // the "recently updated" ordering look like a real working week.
            $ticket->forceFill([
                'created_at' => now()->subDays(14 - $index)->setTime(8 + $index, 15),
            ])->save();

            if ($assignee) {
                $service->assign($ticket, $assignee, $creator);
            }

            foreach ($blueprint['replies'] as $reply) {
                $service->comment(
                    $ticket,
                    $reply['body'],
                    $users[$reply['by']],
                    internal: $reply['internal'] ?? false,
                );
            }

            $target = TicketStatus::query()->where('slug', $blueprint['status'])->first();

            if ($target && ! $target->is($ticket->status)) {
                $this->moveTo($service, $ticket->fresh(), $target, $creator);
            }

            // Backdate the activity stamps last, so the list ordering and the
            // relative timestamps look like a real working fortnight rather
            // than eight tickets that all landed this second.
            $touched = now()->subDays(max(0, 12 - $index * 2))->setTime(9 + $index % 8, ($index * 7) % 60);

            $ticket->newQuery()->whereKey($ticket->getKey())->update([
                'updated_at' => $touched,
                'last_activity_at' => $touched,
            ]);
        }
    }

    /**
     * Walk a ticket to the target status through legal transitions, so the
     * demo data respects the same workflow rules the UI does.
     */
    private function moveTo(TicketService $service, Ticket $ticket, TicketStatus $target, User $actor): void
    {
        $path = match ($target->slug) {
            'resolved' => ['open', 'resolved'],
            'closed' => ['open', 'resolved', 'closed'],
            default => [$target->slug],
        };

        foreach ($path as $slug) {
            $status = TicketStatus::query()->where('slug', $slug)->sole();

            if ($ticket->status->is($status)) {
                continue;
            }

            $service->transition(
                $ticket,
                $status,
                $actor,
                $status->slug === 'resolved' ? 'Opgelost en teruggekoppeld aan de melder.' : null,
            );

            $ticket = $ticket->fresh();
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
