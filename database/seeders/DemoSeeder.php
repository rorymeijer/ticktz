<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\Sla\SweepSlaTimersJob;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\AuditLogEntry;
use App\Models\AutomationRule;
use App\Models\CustomField;
use App\Models\EmailChannel;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Label;
use App\Models\Organization;
use App\Models\PortalCategory;
use App\Models\Priority;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\SlaTimer;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Assets\AssetService;
use App\Services\Kb\ArticleService;
use App\Services\Sla\SlaEngine;
use App\Services\Sla\SlaEscalator;
use App\Services\Tickets\TicketService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
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
        // The demo walks tickets through weeks of history in a few seconds.
        // Mailing all of it would flood the demo accounts with notifications
        // for things that "happened" a fortnight ago — and would fail outright
        // on a machine with no mail server. Collect it in memory instead.
        config(['mail.default' => 'array']);

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingsSeeder::class,
            TicketWorkflowSeeder::class,
            SlaSeeder::class,
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
        $this->seedApprovals();
        $this->seedPortal();
        $this->seedMailbox($teams['servicedesk']);
        $this->seedAutomation($teams);
        $this->seedAssets();
        $this->seedTickets();
        $this->seedKnowledgeBase();
        $this->seedPendingApproval();
        $this->linkAssetsToTickets();
    }

    /**
     * A small estate: two laptops, a phone, a server and the dock one of the
     * laptops is plugged into.
     *
     * Deliberately connected rather than a flat list — a register nothing
     * points at demonstrates only half the feature, and "which laptop is on
     * this dock" is the question a CMDB exists to answer.
     */
    private function seedAssets(): void
    {
        $types = collect([
            ['slug' => 'laptop', 'name' => 'Laptop', 'nl' => 'Laptop', 'prefix' => 'LAP', 'color' => '#4f46e5', 'position' => 10],
            ['slug' => 'monitor', 'name' => 'Monitor', 'nl' => 'Beeldscherm', 'prefix' => 'MON', 'color' => '#0891b2', 'position' => 20],
            ['slug' => 'dock', 'name' => 'Docking station', 'nl' => 'Docking station', 'prefix' => 'DCK', 'color' => '#0d9488', 'position' => 30],
            ['slug' => 'phone', 'name' => 'Phone', 'nl' => 'Telefoon', 'prefix' => 'TEL', 'color' => '#7c3aed', 'position' => 40],
            ['slug' => 'server', 'name' => 'Server', 'nl' => 'Server', 'prefix' => 'SRV', 'color' => '#dc2626', 'position' => 50],
            ['slug' => 'printer', 'name' => 'Printer', 'nl' => 'Printer', 'prefix' => 'PRN', 'color' => '#ca8a04', 'position' => 60],
        ])->mapWithKeys(fn (array $type) => [
            $type['slug'] => AssetType::query()->updateOrCreate(['slug' => $type['slug']], [
                'name' => $type['name'],
                'name_translations' => ['nl' => $type['nl'], 'en' => $type['name']],
                'tag_prefix' => $type['prefix'],
                'color' => $type['color'],
                'is_active' => true,
                'position' => $type['position'],
            ]),
        ]);

        $service = app(AssetService::class);
        $users = User::query()->get()->keyBy('email');
        $organizations = Organization::query()->get()->keyBy('slug');
        $teams = Team::query()->get()->keyBy('slug');

        foreach ($this->assetBlueprints() as $blueprint) {
            if (Asset::query()->withTrashed()->where('asset_tag', $blueprint['tag'])->exists()) {
                continue;
            }

            $owner = isset($blueprint['owner']) ? $users->get($blueprint['owner']) : null;

            $service->create([
                'type' => $types[$blueprint['type']],
                'asset_tag' => $blueprint['tag'],
                'name' => $blueprint['name'],
                'serial_number' => $blueprint['serial'] ?? null,
                'manufacturer' => $blueprint['manufacturer'] ?? null,
                'model' => $blueprint['model'] ?? null,
                'status' => $blueprint['status'] ?? Asset::IN_USE,
                'location' => $blueprint['location'] ?? null,
                'assigned_to' => $owner?->getKey(),
                'organization_id' => $owner?->organization_id
                    ?? $organizations->get($blueprint['organization'] ?? '')?->getKey(),
                'team_id' => $teams->get($blueprint['team'] ?? '')?->getKey(),
                'purchased_at' => $blueprint['purchased'] ?? null,
                'warranty_ends_at' => $blueprint['warranty'] ?? null,
                'purchase_cost' => $blueprint['cost'] ?? null,
                'currency' => isset($blueprint['cost']) ? 'EUR' : null,
                'notes' => $blueprint['notes'] ?? null,
            ]);
        }

        $byTag = Asset::query()->get()->keyBy('asset_tag');

        foreach ($this->assetRelationBlueprints() as [$from, $type, $to]) {
            if ($byTag->has($from) && $byTag->has($to)) {
                $service->relate($byTag[$from], $byTag[$to], $type);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assetBlueprints(): array
    {
        return [
            [
                'tag' => 'LAP-0041', 'type' => 'laptop', 'name' => 'Dell Latitude 5440',
                'serial' => 'DL5440-8827411', 'manufacturer' => 'Dell', 'model' => 'Latitude 5440',
                'owner' => 'm.visser@zandvliet.test', 'location' => 'Stadhuis, 2e etage',
                'purchased' => '2024-03-11', 'warranty' => '2027-03-11', 'cost' => 128900,
            ],
            [
                'tag' => 'LAP-0042', 'type' => 'laptop', 'name' => 'Dell Latitude 5440',
                'serial' => 'DL5440-8827512', 'manufacturer' => 'Dell', 'model' => 'Latitude 5440',
                'owner' => 'a.bouzid@zandvliet.test', 'location' => 'Stadhuis, 3e etage',
                // Deliberately out of warranty: the register should be able to
                // answer "what are we still running that nobody will fix".
                'purchased' => '2021-06-02', 'warranty' => '2024-06-02', 'cost' => 119900,
            ],
            [
                'tag' => 'DCK-0017', 'type' => 'dock', 'name' => 'Dell WD19S dock',
                'serial' => 'WD19S-4412098', 'manufacturer' => 'Dell', 'model' => 'WD19S',
                'owner' => 'a.bouzid@zandvliet.test', 'location' => 'Stadhuis, 3e etage',
                'purchased' => '2021-06-02', 'warranty' => '2024-06-02', 'cost' => 21900,
            ],
            [
                'tag' => 'MON-0088', 'type' => 'monitor', 'name' => 'Dell U2722DE',
                'serial' => 'U2722-1190447', 'manufacturer' => 'Dell', 'model' => 'U2722DE',
                'owner' => 'a.bouzid@zandvliet.test', 'location' => 'Stadhuis, 3e etage',
                'purchased' => '2021-06-02', 'warranty' => '2026-06-02', 'cost' => 44900,
            ],
            [
                'tag' => 'TEL-0203', 'type' => 'phone', 'name' => 'iPhone 14',
                'serial' => 'F2LX90ABCD', 'manufacturer' => 'Apple', 'model' => 'iPhone 14',
                'owner' => 'p.dijkstra@odnoord.test', 'location' => 'Onderweg',
                'purchased' => '2023-09-20', 'warranty' => '2025-09-20', 'cost' => 89900,
            ],
            [
                'tag' => 'PRN-0004', 'type' => 'printer', 'name' => 'Canon iR-ADV C3826i',
                'serial' => 'CNX-3826-771', 'manufacturer' => 'Canon', 'model' => 'iR-ADV C3826i',
                'location' => 'Stadhuis, 3e etage', 'team' => 'servicedesk',
                'organization' => 'gemeente-zandvliet',
                'purchased' => '2022-01-18', 'warranty' => '2027-01-18', 'cost' => 349000,
                'notes' => 'Onderhoudscontract loopt via Canon, contractnummer 8841-B.',
            ],
            [
                'tag' => 'SRV-0002', 'type' => 'server', 'name' => 'zaak-app-01',
                'serial' => 'PE640-2201884', 'manufacturer' => 'Dell', 'model' => 'PowerEdge R640',
                'location' => 'Datacenter, rack 4', 'team' => 'applications',
                'purchased' => '2022-02-14', 'warranty' => '2027-02-14', 'cost' => 745000,
                'notes' => 'Draait het zaaksysteem. Niet herstarten tijdens kantooruren.',
            ],
            [
                'tag' => 'SRV-0003', 'type' => 'server', 'name' => 'backup-01',
                'serial' => 'PE640-2201991', 'manufacturer' => 'Dell', 'model' => 'PowerEdge R640',
                'location' => 'Datacenter, rack 6', 'team' => 'infrastructure',
                'purchased' => '2022-02-14', 'warranty' => '2027-02-14', 'cost' => 612000,
            ],
            [
                'tag' => 'LAP-0055', 'type' => 'laptop', 'name' => 'Dell Latitude 5450',
                'serial' => 'DL5450-9910223', 'manufacturer' => 'Dell', 'model' => 'Latitude 5450',
                'status' => Asset::IN_STOCK, 'location' => 'Magazijn',
                'purchased' => '2026-08-04', 'warranty' => '2029-08-04', 'cost' => 134900,
            ],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function assetRelationBlueprints(): array
    {
        return [
            ['LAP-0042', 'connected_to', 'DCK-0017'],
            ['MON-0088', 'connected_to', 'DCK-0017'],
            ['SRV-0003', 'backs_up', 'SRV-0002'],
        ];
    }

    /**
     * The register attached to the tickets it explains.
     *
     * "What keeps breaking" is the question that changes a purchasing
     * decision, and it is only answerable once the two halves are joined.
     */
    private function linkAssetsToTickets(): void
    {
        $service = app(AssetService::class);
        $assets = Asset::query()->get()->keyBy('asset_tag');

        $pairs = [
            'Printer 3e etage geeft steeds papierstoring' => 'PRN-0004',
            'Zaaksysteem is traag bij het openen van grote dossiers' => 'SRV-0002',
            'Wifi valt weg in vergaderruimte 2.14' => 'LAP-0042',
        ];

        foreach ($pairs as $subject => $tag) {
            $ticket = Ticket::query()->where('subject', $subject)->first();

            if ($ticket && $assets->has($tag)) {
                $service->linkToTicket($assets[$tag], $ticket);
            }
        }
    }

    /**
     * Two approval workflows and the org chart they read.
     *
     * The first is the shape every desk has: the requester's manager signs it
     * off. The second is sequential — the manager, then the team that owns the
     * budget — which is the case a single flag cannot express and the reason
     * steps exist at all.
     */
    private function seedApprovals(): void
    {
        // The manager step reads `users.manager_id`, so the demo needs an org
        // chart or the step resolves to nobody and quietly skips itself.
        $lead = User::query()->where('email', 'rianne@ticktz.test')->first();

        if ($lead) {
            User::query()
                ->whereNot('id', $lead->getKey())
                ->whereNull('manager_id')
                ->update(['manager_id' => $lead->getKey()]);
        }

        $managerSignOff = ApprovalWorkflow::query()->updateOrCreate(['slug' => 'manager-sign-off'], [
            'name' => 'Manager sign-off',
            'name_translations' => ['nl' => 'Akkoord leidinggevende', 'en' => 'Manager sign-off'],
            'description' => 'The requester’s own manager agrees before the desk starts work.',
            'instructions' => 'Your colleague has asked for access. Approve it if the rights fit their role.',
            'is_active' => true,
        ]);

        $this->replaceSteps($managerSignOff, [
            ['mode' => ApprovalStep::ANY, 'approver_type' => 'manager', 'due_hours' => 48],
        ]);

        $budget = ApprovalWorkflow::query()->updateOrCreate(['slug' => 'manager-and-infrastructure'], [
            'name' => 'Manager, then Infrastructure',
            'name_translations' => ['nl' => 'Leidinggevende, dan Infrastructuur', 'en' => 'Manager, then Infrastructure'],
            'description' => 'Two steps, in order: the manager agrees, then the team that pays for it.',
            'instructions' => 'A workplace has been requested. Check the start date and the budget line.',
            'is_active' => true,
        ]);

        $infrastructure = Team::query()->where('slug', 'infrastructure')->first();

        $this->replaceSteps($budget, [
            ['name' => 'Leidinggevende', 'mode' => ApprovalStep::ANY, 'approver_type' => 'manager', 'due_hours' => 48],
            [
                'name' => 'Infrastructuur',
                'mode' => ApprovalStep::ANY,
                'approver_type' => 'team',
                'approver_ids' => $infrastructure ? [$infrastructure->getKey()] : [],
                'due_hours' => 72,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function replaceSteps(ApprovalWorkflow $workflow, array $steps): void
    {
        $workflow->steps()->delete();

        foreach ($steps as $position => $step) {
            $workflow->steps()->create($step + ['position' => $position]);
        }

        $workflow->unsetRelation('steps');
    }

    /**
     * One ticket sitting in an approval, so a fresh demo shows the feature
     * doing its job rather than an empty inbox.
     */
    private function seedPendingApproval(): void
    {
        $ticket = Ticket::query()->where('subject', 'Verzoek: extra mailbox voor team Handhaving')->first();

        if ($ticket === null || $ticket->approvals()->exists()) {
            return;
        }

        $workflow = ApprovalWorkflow::query()->where('slug', 'manager-sign-off')->first();

        if ($workflow === null) {
            return;
        }

        app(ApprovalService::class)->open($ticket, $workflow, [
            'subject' => 'Extra mailbox voor team Handhaving',
            'reason' => 'Een gedeelde mailbox kost een licentie. Graag akkoord van de leidinggevende.',
        ], $ticket->requester);
    }

    /**
     * A small knowledge base: the answers this desk gives most often.
     *
     * Written in Dutch like the rest of the demo, and deliberately mixed —
     * public articles a requester can find, one internal runbook they cannot,
     * and one draft that is not an answer yet. Two are linked to the tickets
     * they would have resolved, because a knowledge base that nothing points
     * at demonstrates only half the feature.
     */
    private function seedKnowledgeBase(): void
    {
        $articles = app(ArticleService::class);
        $author = User::query()->where('email', 'joost@ticktz.test')->first();

        $categories = collect([
            ['slug' => 'accounts', 'name' => 'Accounts en inloggen', 'nl' => 'Accounts en inloggen', 'en' => 'Accounts and sign-in', 'position' => 10],
            ['slug' => 'werkplek', 'name' => 'Werkplek', 'nl' => 'Werkplek', 'en' => 'Workplace', 'position' => 20],
            ['slug' => 'netwerk', 'name' => 'Netwerk en wifi', 'nl' => 'Netwerk en wifi', 'en' => 'Network and wi-fi', 'position' => 30],
            ['slug' => 'runbooks', 'name' => 'Runbooks', 'nl' => 'Runbooks', 'en' => 'Runbooks', 'position' => 40, 'internal' => true],
        ])->mapWithKeys(fn (array $category) => [
            $category['slug'] => KbCategory::query()->updateOrCreate(['slug' => $category['slug']], [
                'name' => $category['name'],
                'name_translations' => ['nl' => $category['nl'], 'en' => $category['en']],
                'visibility' => ($category['internal'] ?? false) ? KbCategory::INTERNAL : KbCategory::PUBLIC,
                'is_active' => true,
                'position' => $category['position'],
            ]),
        ]);

        foreach ($this->articleBlueprints() as $blueprint) {
            $slug = Str::slug($blueprint['title']);

            // Idempotent like the rest of the seeder: re-running the demo must
            // not leave a second copy of every article behind.
            if (KbArticle::query()->withTrashed()->where('slug', $slug)->exists()) {
                continue;
            }

            $article = $articles->create([
                'title' => $blueprint['title'],
                'kb_category_id' => $categories[$blueprint['category']]->getKey(),
                'body' => $blueprint['body'],
                'status' => $blueprint['status'] ?? KbArticle::PUBLISHED,
                'visibility' => $blueprint['visibility'] ?? KbArticle::PUBLIC,
                // No locale: this desk writes in Dutch and every reader should
                // find these, whichever language they run the UI in. Pinning
                // them to `nl` would empty the help centre for an English
                // reader, which is the wrong lesson for a demo to teach.
                'locale' => null,
                'position' => $blueprint['position'] ?? 100,
            ], $author);

            if (isset($blueprint['answers'])) {
                $ticket = Ticket::query()->where('subject', $blueprint['answers'])->first();

                if ($ticket) {
                    $articles->linkToTicket($article, $ticket, $author);
                }
            }

            // A plausible read count, so "most read" orders the list the way a
            // live instance would rather than by insertion order.
            KbArticle::query()->whereKey($article->getKey())->toBase()
                ->update(['view_count' => $blueprint['views'] ?? 0]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articleBlueprints(): array
    {
        return [
            [
                'title' => 'Wachtwoord opnieuw instellen',
                'category' => 'accounts',
                'views' => 412,
                'position' => 10,
                'body' => <<<'HTML'
                    <p>Je kunt je wachtwoord zelf opnieuw instellen. Dat gaat sneller dan een melding maken.</p>
                    <ol>
                        <li>Ga naar <a href="https://wachtwoord.zandvliet.test">wachtwoord.zandvliet.test</a>.</li>
                        <li>Vul je werkmailadres in en klik op <strong>Verstuur code</strong>.</li>
                        <li>Voer de code uit de sms in en kies een nieuw wachtwoord.</li>
                    </ol>
                    <h2>Eisen aan het wachtwoord</h2>
                    <ul>
                        <li>Minimaal twaalf tekens.</li>
                        <li>Geen wachtwoord dat je eerder hebt gebruikt.</li>
                        <li>Een zin werkt beter dan een woord met tekens erin.</li>
                    </ul>
                    <p>Lukt het niet? Maak dan een melding aan, dan zetten wij het account klaar.</p>
                    HTML,
            ],
            [
                'title' => 'Melding "ongeldige sessie" in het zaaksysteem',
                'category' => 'accounts',
                'views' => 268,
                'position' => 20,
                'answers' => 'Kan niet inloggen op het zaaksysteem',
                'body' => <<<'HTML'
                    <p>Deze melding verschijnt als je account op twee werkplekken tegelijk actief is.</p>
                    <h2>Wat je zelf kunt doen</h2>
                    <ol>
                        <li>Sluit het zaaksysteem op alle werkplekken, ook thuis.</li>
                        <li>Wacht vijf minuten; de sessie loopt dan vanzelf af.</li>
                        <li>Log opnieuw in.</li>
                    </ol>
                    <p>Blijft de melding komen, meld het dan bij de servicedesk. Vermeld op welke
                    werkplekken je hebt ingelogd — dat scheelt ons het uitzoekwerk.</p>
                    HTML,
            ],
            [
                'title' => 'Papierstoring verhelpen bij de Canon-printers',
                'category' => 'werkplek',
                'views' => 193,
                'position' => 10,
                'answers' => 'Printer 3e etage geeft steeds papierstoring',
                'body' => <<<'HTML'
                    <p>Bijna alle papierstoringen los je zelf op in twee minuten.</p>
                    <ol>
                        <li>Open de achterklep (de grijze hendel rechtsonder).</li>
                        <li>Trek het vastgelopen vel <em>met de papierrichting mee</em> naar buiten, nooit terug.</li>
                        <li>Controleer of er geen snippers zijn achtergebleven.</li>
                        <li>Sluit de klep. De printer hervat de opdracht zelf.</li>
                    </ol>
                    <h2>Blijft het gebeuren?</h2>
                    <p>Dan ligt het meestal aan het papier, niet aan de printer:</p>
                    <ul>
                        <li>Papier dat te lang open heeft gelegen, trekt vocht.</li>
                        <li>Een lade die te vol zit, pakt twee vellen tegelijk.</li>
                        <li>Waaier de stapel los voordat je hem in de lade legt.</li>
                    </ul>
                    HTML,
            ],
            [
                'title' => 'Een nieuwe werkplek aanvragen',
                'category' => 'werkplek',
                'views' => 154,
                'position' => 20,
                'body' => <<<'HTML'
                    <p>Vraag een werkplek aan zodra de startdatum bekend is. Wij hebben tien werkdagen
                    nodig, en bij levertijden van leveranciers soms langer.</p>
                    <h2>Wat we nodig hebben</h2>
                    <ul>
                        <li>Naam van de nieuwe collega.</li>
                        <li>Eerste werkdag.</li>
                        <li>Afdeling en leidinggevende.</li>
                        <li>Of er afwijkende apparatuur nodig is.</li>
                    </ul>
                    <p>Gebruik het formulier <strong>Nieuwe medewerker</strong> op het portaal; dan
                    vragen we die gegevens meteen goed uit.</p>
                    HTML,
            ],
            [
                'title' => 'Wifi in de vergaderruimtes',
                'category' => 'netwerk',
                'views' => 121,
                'position' => 10,
                'body' => <<<'HTML'
                    <p>De vergaderruimtes hangen aan hetzelfde netwerk als de werkplekken, maar aan
                    één accesspoint per ruimte.</p>
                    <h2>Verbinding valt weg tijdens een overleg</h2>
                    <ul>
                        <li>Zet wifi op je laptop uit en weer aan; je verbindt dan opnieuw met het sterkste punt.</li>
                        <li>Deel geen scherm via wifi als het via de kabel kan — dat scheelt de helft van de bandbreedte.</li>
                        <li>Zitten er meer dan acht mensen, meld het dan bij ons. Dan is de ruimte te krap bemeten.</li>
                    </ul>
                    HTML,
            ],
            [
                'title' => 'Sessietabel zaaksysteem opschonen',
                'category' => 'runbooks',
                'visibility' => KbArticle::INTERNAL,
                'views' => 47,
                'position' => 10,
                'body' => <<<'HTML'
                    <p><strong>Alleen voor beheerders.</strong> Deze ingreep raakt alle actieve sessies.</p>
                    <ol>
                        <li>Log in op <code>zaak-app-01</code>.</li>
                        <li>Controleer eerst welke rijen je raakt:
                            <code>SELECT id, user_id, last_seen_at FROM sessions WHERE last_seen_at &lt; NOW() - INTERVAL 2 HOUR;</code></li>
                        <li>Verwijder ze pas daarna, in batches van duizend.</li>
                        <li>Noteer het rij-id in het ticket, zodat de volgende collega ziet wat je hebt gedaan.</li>
                    </ol>
                    <blockquote>Nooit uitvoeren tijdens kantooruren zonder overleg met Applicatiebeheer.</blockquote>
                    HTML,
            ],
            [
                'title' => 'Mailbox voor een team aanvragen',
                'category' => 'accounts',
                'status' => KbArticle::DRAFT,
                'position' => 30,
                'body' => <<<'HTML'
                    <p>Concept — nog af te stemmen met Functioneel beheer.</p>
                    <p>Een gedeelde mailbox vraag je aan via het portaal. Vermeld wie de eigenaar is
                    en wie er toegang moeten krijgen.</p>
                    HTML,
            ],
        ];
    }

    /**
     * Three rules that show the shape of the feature without being clever.
     *
     * Each is a thing a real desk actually does: route by label, escalate the
     * customer chasing an urgent ticket, and nudge the ones that have gone
     * quiet. The third is scheduled, so the demo has one of those too.
     *
     * @param  Collection<string, Team>  $teams
     */
    private function seedAutomation(Collection $teams): void
    {
        $labels = Label::query()->pluck('id', 'slug');
        $priorities = Priority::query()->pluck('id', 'slug');

        AutomationRule::query()->updateOrCreate(['slug' => 'hardware-to-infrastructure'], [
            'name' => 'Hardware gaat naar Infrastructuur',
            'description' => 'Een nieuw ticket met het label Hardware komt bij het juiste team terecht.',
            'trigger' => AutomationRule::TRIGGER_CREATED,
            'match_type' => 'all',
            'conditions' => [
                ['field' => 'label', 'operator' => 'is', 'value' => $labels['hardware'] ?? 0],
            ],
            'actions' => [
                ['type' => 'assign_team', 'team_id' => $teams['infrastructure']->getKey()],
            ],
            'is_active' => true,
            'position' => 10,
        ]);

        AutomationRule::query()->updateOrCreate(['slug' => 'requester-chases-urgent'], [
            'name' => 'Melder rappelleert op een urgent ticket',
            'description' => 'Een reactie van de melder op een urgent ticket zet het terug op de stapel.',
            'trigger' => AutomationRule::TRIGGER_COMMENTED,
            'match_type' => 'all',
            'conditions' => [
                ['field' => 'comment_is_internal', 'operator' => 'is_false'],
                ['field' => 'priority', 'operator' => 'is', 'value' => $priorities['urgent'] ?? 0],
            ],
            'actions' => [
                ['type' => 'add_comment', 'body' => 'Melder heeft gereageerd op {{ ticket.key }} — graag oppakken.', 'internal' => true],
            ],
            'is_active' => true,
            'position' => 20,
        ]);

        AutomationRule::query()->updateOrCreate(['slug' => 'nudge-quiet-tickets'], [
            'name' => 'Stille tickets aanstippen',
            'description' => 'Tickets waar drie dagen niets op gebeurd is krijgen een interne notitie.',
            'trigger' => AutomationRule::TRIGGER_SCHEDULED,
            'trigger_config' => ['cron' => '0 8 * * 1-5'],
            'match_type' => 'all',
            'conditions' => [
                ['field' => 'minutes_since_activity', 'operator' => 'greater_than', 'value' => 4320],
                ['field' => 'is_assigned', 'operator' => 'is_true'],
            ],
            'actions' => [
                ['type' => 'add_comment', 'body' => 'Er is drie dagen niets gebeurd op {{ ticket.key }}.', 'internal' => true],
            ],
            // Off by default: a demo that starts commenting on its own tickets
            // the moment somebody runs the scheduler is a demo that confuses.
            'is_active' => false,
            'position' => 30,
        ]);
    }

    /**
     * The demo mailbox, wired to the GreenMail container in the development
     * stack: mail sent to servicedesk@ticktz.test lands there, and the poller
     * reads it back over IMAP and turns it into a ticket.
     *
     * GreenMail runs with authentication disabled and creates a mailbox on
     * first use, so the credentials below are placeholders rather than
     * secrets. Point the host at a real server for anything but a demo.
     */
    private function seedMailbox(Team $servicedesk): void
    {
        $host = (string) (config('mail.mailers.smtp.host') ?: 'mail');

        EmailChannel::query()->updateOrCreate(['slug' => 'servicedesk'], [
            'name' => 'Service desk',
            'address' => 'servicedesk@ticktz.test',
            'from_name' => 'Gemeente Ticktz — Service desk',
            'is_active' => true,

            'smtp_host' => $host,
            'smtp_port' => (int) (config('mail.mailers.smtp.port') ?: 3025),
            'smtp_encryption' => 'none',

            'imap_enabled' => true,
            'imap_host' => $host,
            'imap_port' => 3143,
            'imap_encryption' => 'none',
            'imap_validate_cert' => false,
            'imap_username' => 'servicedesk@ticktz.test',
            'imap_password' => 'ticktz-demo',
            'imap_folder' => 'INBOX',

            'team_id' => $servicedesk->getKey(),
            'auto_provision_requesters' => true,
            'ignore_senders' => [],
        ]);
    }

    /**
     * A small but complete portal catalogue: two categories, four request
     * types and the custom fields their forms ask for.
     */
    private function seedPortal(): void
    {
        $fields = [
            [
                'key' => 'start_date',
                'label' => 'Start date',
                'label_translations' => ['nl' => 'Startdatum'],
                'type' => 'date',
                'is_required' => true,
                'help_text' => 'The first working day of the new colleague.',
            ],
            [
                'key' => 'employee_name',
                'label' => 'Name of the employee',
                'label_translations' => ['nl' => 'Naam van de medewerker'],
                'type' => 'text',
                'is_required' => true,
            ],
            [
                'key' => 'department',
                'label' => 'Department',
                'label_translations' => ['nl' => 'Afdeling'],
                'type' => 'select',
                'is_required' => true,
                'options' => [
                    ['value' => 'permits', 'label' => 'Permits'],
                    ['value' => 'enforcement', 'label' => 'Enforcement'],
                    ['value' => 'civil-affairs', 'label' => 'Civil affairs'],
                    ['value' => 'communications', 'label' => 'Communications'],
                ],
            ],
            [
                'key' => 'equipment',
                'label' => 'Equipment needed',
                'label_translations' => ['nl' => 'Benodigde apparatuur'],
                'type' => 'multiselect',
                'options' => [
                    ['value' => 'laptop', 'label' => 'Laptop'],
                    ['value' => 'monitor', 'label' => 'Extra monitor'],
                    ['value' => 'phone', 'label' => 'Mobile phone'],
                    ['value' => 'headset', 'label' => 'Headset'],
                ],
            ],
            [
                'key' => 'system_name',
                'label' => 'Which system?',
                'label_translations' => ['nl' => 'Welk systeem?'],
                'type' => 'select',
                'is_required' => true,
                'options' => [
                    ['value' => 'case-management', 'label' => 'Case management'],
                    ['value' => 'dms', 'label' => 'Document management'],
                    ['value' => 'gis', 'label' => 'GIS / map viewer'],
                    ['value' => 'finance', 'label' => 'Finance package'],
                ],
            ],
            [
                'key' => 'error_message',
                'label' => 'Exact error message',
                'label_translations' => ['nl' => 'Exacte foutmelding'],
                'type' => 'textarea',
                'help_text' => 'Copy it literally, or attach a screenshot below.',
            ],
            [
                'key' => 'affects_others',
                'label' => 'Are colleagues affected too?',
                'label_translations' => ['nl' => 'Hebben collega’s er ook last van?'],
                'type' => 'checkbox',
            ],
            [
                'key' => 'cost_centre',
                'label' => 'Cost centre',
                'label_translations' => ['nl' => 'Kostenplaats'],
                'type' => 'text',
                'is_public' => false,
                'help_text' => 'Filled in by the service desk for the invoice.',
            ],
        ];

        $position = 0;

        foreach ($fields as $field) {
            $position += 10;

            CustomField::query()->updateOrCreate(['key' => $field['key']], $field + [
                'entity' => 'ticket',
                'is_active' => true,
                'is_public' => $field['is_public'] ?? true,
                'is_required' => $field['is_required'] ?? false,
                'position' => $position,
            ]);
        }

        $categories = [
            [
                'slug' => 'workplace',
                'name' => 'Workplace',
                'name_translations' => ['nl' => 'Werkplek'],
                'description' => 'Hardware, software and everything on your desk.',
                'description_translations' => ['nl' => 'Hardware, software en alles op je bureau.'],
                'color' => '#0891b2',
                'position' => 10,
            ],
            [
                'slug' => 'applications',
                'name' => 'Applications',
                'name_translations' => ['nl' => 'Applicaties'],
                'description' => 'The systems you work in every day.',
                'description_translations' => ['nl' => 'De systemen waarin je dagelijks werkt.'],
                'color' => '#7c3aed',
                'position' => 20,
            ],
        ];

        $categoryModels = collect($categories)->mapWithKeys(fn (array $category) => [
            $category['slug'] => PortalCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_active' => true],
            ),
        ]);

        $teams = Team::query()->get()->keyBy('slug');
        $priorities = Priority::query()->get()->keyBy('slug');

        $requestTypes = [
            [
                'slug' => 'new-colleague',
                'category' => 'workplace',
                'name' => 'Onboard a new colleague',
                'name_translations' => ['nl' => 'Nieuwe collega aanmelden'],
                'description' => 'Request a workplace, accounts and equipment for someone starting soon.',
                'description_translations' => ['nl' => 'Vraag een werkplek, accounts en apparatuur aan voor iemand die binnenkort begint.'],
                'instructions' => "Please submit this at least five working days before the start date.\n\nWe will create the accounts, prepare the hardware and send a collection time.",
                'instructions_translations' => ['nl' => "Dien dit minimaal vijf werkdagen voor de startdatum in.\n\nWij maken de accounts aan, zetten de hardware klaar en sturen een ophaalmoment."],
                'team' => 'infrastructure',
                'priority' => 'normal',
                'subject_template' => 'New colleague: :employee_name (:department)',
                'fields' => ['employee_name', 'start_date', 'department', 'equipment'],
                'approval' => 'manager-and-infrastructure',
                'position' => 10,
            ],
            [
                'slug' => 'hardware-problem',
                'category' => 'workplace',
                'name' => 'Something is broken',
                'name_translations' => ['nl' => 'Er is iets kapot'],
                'description' => 'A laptop, monitor, printer or phone that no longer works.',
                'description_translations' => ['nl' => 'Een laptop, monitor, printer of telefoon die het niet meer doet.'],
                'team' => 'servicedesk',
                'priority' => 'normal',
                'allow_priority_choice' => true,
                'fields' => ['error_message', 'affects_others'],
                'position' => 20,
            ],
            [
                'slug' => 'application-problem',
                'category' => 'applications',
                'name' => 'An application is not working',
                'name_translations' => ['nl' => 'Een applicatie werkt niet'],
                'description' => 'Report an error, something slow, or behaviour you do not expect.',
                'description_translations' => ['nl' => 'Meld een foutmelding, traagheid of gedrag dat je niet verwacht.'],
                'team' => 'applications',
                'priority' => 'high',
                'allow_priority_choice' => true,
                'subject_template' => ':system_name — problem reported',
                'fields' => ['system_name', 'error_message', 'affects_others'],
                'position' => 30,
            ],
            [
                'slug' => 'application-access',
                'category' => 'applications',
                'name' => 'Request access to an application',
                'name_translations' => ['nl' => 'Toegang tot een applicatie aanvragen'],
                'description' => 'Ask for an account or extra rights in a system.',
                'description_translations' => ['nl' => 'Vraag een account of extra rechten in een systeem aan.'],
                'instructions' => 'Access requests are checked with your manager before they are granted.',
                'instructions_translations' => ['nl' => 'Toegangsverzoeken worden met je leidinggevende afgestemd voordat ze worden verleend.'],
                'team' => 'applications',
                'priority' => 'normal',
                'subject_template' => 'Access request: :system_name',
                'fields' => ['system_name', 'department'],
                'approval' => 'manager-sign-off',
                'position' => 40,
            ],
        ];

        $allFields = CustomField::query()->get()->keyBy('key');
        $approvals = ApprovalWorkflow::query()->get()->keyBy('slug');

        foreach ($requestTypes as $definition) {
            $type = RequestType::query()->updateOrCreate(['slug' => $definition['slug']], [
                'portal_category_id' => $categoryModels[$definition['category']]->getKey(),
                'name' => $definition['name'],
                'name_translations' => $definition['name_translations'],
                'description' => $definition['description'],
                'description_translations' => $definition['description_translations'],
                'instructions' => $definition['instructions'] ?? null,
                'instructions_translations' => $definition['instructions_translations'] ?? null,
                'team_id' => isset($definition['team']) ? $teams[$definition['team']]->getKey() : null,
                'priority_id' => isset($definition['priority']) ? $priorities[$definition['priority']]->getKey() : null,
                'allow_priority_choice' => $definition['allow_priority_choice'] ?? false,
                'subject_template' => $definition['subject_template'] ?? null,
                'approval_workflow_id' => isset($definition['approval'])
                    ? $approvals[$definition['approval']]->getKey()
                    : null,
                'visibility' => 'everyone',
                'is_active' => true,
                'position' => $definition['position'],
            ]);

            $fieldPosition = 0;

            $type->fields()->sync(
                collect($definition['fields'])
                    ->mapWithKeys(function (string $key) use ($allFields, &$fieldPosition): array {
                        $fieldPosition += 10;

                        return [$allFields[$key]->getKey() => ['position' => $fieldPosition]];
                    })
                    ->all()
            );
        }
    }

    private function seedLabels(): void
    {
        $labels = [
            ['name' => 'Hardware', 'name_translations' => ['nl' => 'Hardware'], 'slug' => 'hardware', 'color' => '#0891b2'],
            ['name' => 'Account', 'name_translations' => ['nl' => 'Account'], 'slug' => 'account', 'color' => '#7c3aed'],
            ['name' => 'Network', 'name_translations' => ['nl' => 'Netwerk'], 'slug' => 'netwerk', 'color' => '#059669'],
            ['name' => 'Case management', 'name_translations' => ['nl' => 'Zaaksysteem'], 'slug' => 'zaaksysteem', 'color' => '#d97706'],
            ['name' => 'Freedom of information', 'name_translations' => ['nl' => 'Wet open overheid'], 'slug' => 'woo', 'color' => '#dc2626'],
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

    /**
     * How old each ticket in `ticketBlueprints()` is, in hours, oldest first.
     * Roughly: a fortnight, a week, a few days, then today.
     *
     * @var array<int, int>
     */
    private const AGES_IN_HOURS = [330, 260, 190, 120, 70, 26, 5, 2];

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

            // Age the ticket. The spread is deliberate rather than even: a
            // desk has a tail of old cases that have long blown their targets
            // and a head of fresh ones still comfortably inside them, and a
            // demo where every clock is the same colour teaches nothing.
            $ticket->forceFill([
                'created_at' => now()->subHours(self::AGES_IN_HOURS[$index] ?? 24),
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
            $touched = now()->subHours(max(1, (int) ((self::AGES_IN_HOURS[$index] ?? 24) / 3)));

            $ticket->newQuery()->whereKey($ticket->getKey())->update([
                'updated_at' => $touched,
                'last_activity_at' => $touched,
            ]);

            $this->backdateHistory($ticket);
            $this->backdateSlaClocks($ticket);
        }

        // The clocks now start in the past, so some of them have run out.
        // Running the sweep the way the scheduler does gives the demo a
        // realistic mix — met, running, paused and breached — rather than
        // eight tickets that all look comfortably on track.
        app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));
    }

    /**
     * Spread a ticket's comments and audit entries across its lifetime.
     *
     * Everything the seeder does happens in the same second, so without this
     * a ticket filed a fortnight ago shows a conversation that all took place
     * just now — and, once SLA clocks are backdated too, a first response
     * recorded as met a fortnight before the reply that met it. The ordering
     * is preserved: only the timestamps move.
     */
    private function backdateHistory(Ticket $ticket): void
    {
        $start = $ticket->created_at;
        $comments = $ticket->comments()->orderBy('id')->get()->keyBy('id');
        $entries = AuditLogEntry::query()->forSubject($ticket)->orderBy('id')->get();

        // One ordered sequence, so "changed the status" lands after the reply
        // that preceded it rather than before every comment on the ticket. An
        // audit entry that carries a comment id is that comment's own moment,
        // and both get the same timestamp.
        $sequence = $entries->map(fn (AuditLogEntry $entry) => [
            'entry' => $entry,
            'comment' => $comments->get($entry->context['comment_id'] ?? null),
        ])->values();

        // Real desks answer in a burst and then go quiet. Compressing the
        // conversation into the first hours of the ticket's life keeps the
        // response targets meaningful: replying a week later would breach
        // every first-response goal in the demo.
        $window = (int) min($start->diffInMinutes(now()) * 0.5, 4 * 60);
        $steps = max(1, $sequence->count());

        foreach ($sequence as $index => $item) {
            $at = $start->copy()->addMinutes((int) ($window * (($index + 1) / $steps)));

            // The audit log is append-only and has no updated_at.
            $item['entry']->newQuery()->whereKey($item['entry']->getKey())->update(['created_at' => $at]);

            $item['comment']?->newQuery()
                ->whereKey($item['comment']->getKey())
                ->update(['created_at' => $at, 'updated_at' => $at]);
        }

        // The response stamps the SLA and reporting read must match the
        // conversation they were derived from.
        $ticket->refresh();

        $firstPublicReply = $ticket->comments()
            ->where('is_internal', false)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $ticket->requester_id))
            ->orderBy('created_at')
            ->first();

        if ($firstPublicReply) {
            $ticket->newQuery()->whereKey($ticket->getKey())->update([
                'first_response_at' => $firstPublicReply->created_at,
                'last_public_reply_at' => $firstPublicReply->created_at,
            ]);
        }

        $lastEvent = $entries->last();

        if ($lastEvent) {
            $at = $lastEvent->newQuery()->whereKey($lastEvent->getKey())->value('created_at');

            // Resolution timestamps are derived from the transition that set
            // them, so the resolution clock is judged against a real moment.
            $ticket->newQuery()->whereKey($ticket->getKey())->update(array_filter([
                'resolved_at' => $ticket->resolved_at ? $at : null,
                'closed_at' => $ticket->closed_at ? $at : null,
            ]));
        }
    }

    /**
     * Move a ticket's SLA clocks back to when the ticket was actually filed.
     *
     * The engine starts a clock at `now()`, which is correct in production and
     * wrong for fabricated history: a ticket dated a fortnight ago would carry
     * a deadline a fortnight in the future. The whole clock — start, target and
     * completion — is shifted by the same interval, so the arithmetic that
     * decided met or breached still holds.
     */
    private function backdateSlaClocks(Ticket $ticket): void
    {
        $ticket->refresh()->load('slaTimers.calendar');

        foreach ($ticket->slaTimers as $timer) {
            $shift = $timer->started_at->diffInSeconds($ticket->created_at, false);
            $startedAt = $timer->started_at->addSeconds($shift);
            $dueAt = $timer->due_at->addSeconds($shift);

            // A finished clock is re-dated from the event it measures rather
            // than shifted with the rest: the first response happened when the
            // agent actually replied, and whether that beat the target has to
            // be decided against the real moment, or the demo shows a target
            // met an hour after a ticket was filed by a reply sent a week later.
            $completedAt = match ($timer->metric) {
                'first_response' => $ticket->first_response_at,
                'resolution' => $ticket->resolved_at ?? $ticket->closed_at,
                default => null,
            };

            $update = [
                'started_at' => $startedAt,
                'due_at' => $dueAt,
                'paused_at' => $timer->paused_at?->addSeconds($shift),
            ];

            if ($timer->completed_at && $completedAt) {
                $met = $completedAt->lessThanOrEqualTo($dueAt);

                $update += [
                    'completed_at' => $completedAt,
                    'breached_at' => $met ? null : $completedAt,
                    'status' => $met ? SlaTimer::STATUS_MET : SlaTimer::STATUS_BREACHED,
                ];
            } else {
                $update += ['breached_at' => $timer->breached_at?->addSeconds($shift)];
            }

            $timer->newQuery()->whereKey($timer->getKey())->update($update);
        }

        $ticket->load('slaTimers');
        app(SlaEngine::class)->syncTicket($ticket);
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
