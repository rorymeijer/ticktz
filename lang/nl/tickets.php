<?php

declare(strict_types=1);

return [
    'title' => 'Tickets',
    'subtitle' => 'Alles waar de servicedesk aan werkt.',
    'create' => 'Nieuw ticket',
    'ticket' => 'Ticket',

    'columns' => [
        'key' => 'Nummer',
        'subject' => 'Onderwerp',
        'status' => 'Status',
        'priority' => 'Prioriteit',
        'requester' => 'Melder',
        'assignee' => 'Behandelaar',
        'team' => 'Team',
        'organization' => 'Organisatie',
        'labels' => 'Labels',
        'created_at' => 'Aangemaakt',
        'updated_at' => 'Bijgewerkt',
        'source' => 'Bron',
        'sla' => 'Serviceniveau',
    ],

    'fields' => [
        'subject' => 'Onderwerp',
        'description' => 'Omschrijving',
        'requester' => 'Melder',
        'assignee' => 'Behandelaar',
        'priority' => 'Prioriteit',
        'status' => 'Status',
        'team' => 'Team',
        'queue' => 'Wachtrij',
        'labels' => 'Labels',
        'attachments' => 'Bijlagen',
        'watchers' => 'Volgers',
        'links' => 'Gekoppelde tickets',
        'workflow' => 'Workflow',
    ],

    'placeholders' => [
        'subject' => 'Vat het probleem samen in één regel',
        'description' => 'Wat gebeurde er, wat verwachtte je, en wat heb je al geprobeerd',
        'search' => 'Zoek op nummer, onderwerp of omschrijving…',
        'reply' => 'Schrijf een antwoord aan de melder…',
        'note' => 'Schrijf een interne notitie — de melder ziet dit nooit…',
    ],

    'filters' => [
        'title' => 'Filters',
        'status' => 'Alle statussen',
        'priority' => 'Alle prioriteiten',
        'assignee' => 'Iedereen',
        'unassigned' => 'Niet toegewezen',
        'assigned_to_me' => 'Aan mij toegewezen',
        'team' => 'Alle teams',
        'my_teams' => 'Mijn teams',
        'label' => 'Alle labels',
        'source' => 'Alle bronnen',
        'clear' => 'Filters wissen',
    ],

    'source' => [
        'portal' => 'Portaal',
        'email' => 'E-mail',
        'agent' => 'Agent',
        'api' => 'API',
        'automation' => 'Automatisering',
    ],

    'status_category' => [
        'new' => 'Nieuw',
        'open' => 'Open',
        'pending' => 'Wachtend',
        'resolved' => 'Opgelost',
        'closed' => 'Gesloten',
    ],

    'actions' => [
        'reply' => 'Antwoorden',
        'internal_note' => 'Interne notitie',
        'send_reply' => 'Antwoord versturen',
        'save_note' => 'Notitie opslaan',
        'assign' => 'Toewijzen',
        'claim' => 'Aan mij toewijzen',
        'unassign' => 'Toewijzing opheffen',
        'watch' => 'Volgen',
        'unwatch' => 'Niet meer volgen',
        'add_watcher' => 'Volger toevoegen',
        'link' => 'Ticket koppelen',
        'unlink' => 'Koppeling verwijderen',
        'change_status' => 'Status wijzigen',
        'attach' => 'Bestanden toevoegen',
    ],

    'timeline' => [
        'title' => 'Activiteit',
        'system' => 'Systeem',
        'internal_note' => 'Interne notitie',
        'public_reply' => 'Antwoord',
        'edited' => 'bewerkt',
        'no_activity' => 'Er is nog niets gebeurd.',
        'events' => [
            'created' => 'heeft dit ticket aangemaakt',
            'ticket.assigned' => 'heeft de behandelaar gewijzigd',
            'ticket.unassigned' => 'heeft de behandelaar verwijderd',
            'ticket.transitioned' => 'heeft de status gewijzigd',
        ],
    ],

    'link_types' => [
        'relates' => 'heeft te maken met',
        'duplicates' => 'is een duplicaat van',
        'duplicated_by' => 'heeft als duplicaat',
        'blocks' => 'blokkeert',
        'blocked_by' => 'wordt geblokkeerd door',
        'causes' => 'veroorzaakt',
        'caused_by' => 'wordt veroorzaakt door',
        'parent' => 'is bovenliggend aan',
        'child' => 'is onderdeel van',
    ],

    'detail' => [
        'properties' => 'Eigenschappen',
        'people' => 'Betrokkenen',
        'opened_by' => 'Gemeld door :name',
        'first_response' => 'Eerste reactie',
        'resolved' => 'Opgelost',
        'reopened' => ':count keer heropend|:count keer heropend',
        'no_watchers' => 'Niemand volgt dit ticket.',
        'no_links' => 'Geen gekoppelde tickets.',
        'internal_only' => 'Alleen agents zien dit.',
    ],

    'queues' => [
        'title' => 'Wachtrijen',
        'subtitle' => 'Opgeslagen filters op de ticketlijst. Een ticket kan in meerdere tegelijk staan.',
        'all_tickets' => 'Alle tickets',
        'ticket_count' => ':count ticket|:count tickets',
        'empty' => 'Er zijn geen wachtrijen voor je beschikbaar.',
    ],

    'empty' => [
        'title' => 'Geen tickets',
        'description' => 'Niets voldoet aan de huidige filters.',
        'create' => 'Maak het eerste ticket',
    ],

    'flash' => [
        'created' => 'Ticket :key aangemaakt.',
        'updated' => 'Ticket bijgewerkt.',
        'deleted' => 'Ticket verwijderd.',
        'replied' => 'Antwoord verstuurd.',
        'note_added' => 'Interne notitie opgeslagen.',
        'assigned' => 'Behandelaar bijgewerkt.',
        'unassigned' => 'Behandelaar verwijderd.',
        'claimed' => 'Ticket aan jou toegewezen.',
        'transitioned' => 'Status gewijzigd naar :status.',
        'watching' => 'Je volgt dit ticket nu.',
        'not_watching' => 'Je volgt dit ticket niet meer.',
        'watcher_added' => 'Volger toegevoegd.',
        'watcher_removed' => 'Volger verwijderd.',
        'linked' => 'Gekoppeld aan :key.',
        'unlinked' => 'Koppeling verwijderd.',
        'attachment_deleted' => 'Bijlage verwijderd.',
    ],

    'errors' => [
        'illegal_transition' => 'De workflow staat de stap van :from naar :to niet toe.',
        'approval_required' => 'Voor deze stap is eerst goedkeuring nodig, en die is er niet.',
        'comment_required' => 'Deze statuswijziging vereist een toelichting.',
        'assignee_required' => 'Wijs het ticket toe voordat je naar deze status gaat.',
        'assignee_not_agent' => 'Alleen agents kunnen tickets toegewezen krijgen.',
        'link_not_found' => 'Geen ticket met dat nummer.',
        'link_self' => 'Een ticket kan niet aan zichzelf gekoppeld worden.',
    ],
];
