<?php

declare(strict_types=1);

return [
    'title' => 'Rapportage',
    'subtitle' => 'Wat de servicedesk deed, over een periode die je zelf kiest.',
    'no_data' => 'Niets gemeten in deze periode.',
    'unassigned' => 'Niet toegewezen',

    'reports' => [
        'sla' => 'SLA-naleving',
        'volume' => 'Ticketvolume',
        'workload' => 'Werkverdeling',
        'cycle_time' => 'Doorlooptijd',
    ],

    'descriptions' => [
        'sla' => 'Van de SLA-klokken die in deze periode afliepen: hoeveel zijn er gehaald.',
        'volume' => 'Hoeveel er binnenkwam, en hoeveel de servicedesk wegwerkte.',
        'workload' => 'Wie wat oploste, en hoe lang ze erover deden.',
        'cycle_time' => 'Tijd tot het eerste antwoord, en tijd tot een oplossing.',
    ],

    'filters' => [
        'from' => 'Van',
        'to' => 'Tot',
        'dimension' => 'Uitsplitsen naar',
        'apply' => 'Toepassen',
        'presets' => [
            'week' => 'Laatste 7 dagen',
            'month' => 'Laatste 30 dagen',
            'quarter' => 'Laatste 90 dagen',
            'year' => 'Laatste 12 maanden',
        ],
    ],

    'dimensions' => [
        'all' => 'Niets',
        'queue' => 'Wachtrij',
        'team' => 'Team',
        'assignee' => 'Behandelaar',
        'priority' => 'Prioriteit',
        'request_type' => 'Aanvraagtype',
        'organization' => 'Organisatie',
    ],

    'sla' => [
        'title' => 'SLA-naleving',
        'compliance' => 'Naleving',
        'met' => 'Gehaald',
        'breached' => 'Niet gehaald',
        'first_response' => 'Eerste reactie',
        'resolution' => 'Oplossing',
        'outcomes' => 'Afgelopen klokken',
        'per_day' => 'Gehaald en niet gehaald, per dag',
        'breakdown' => 'Naleving per :dimension',
        'note' => 'Een SLA-klok telt mee op de dag dat hij afliep, niet op de dag dat het ticket binnenkwam — zo verschuift een cijfer niet meer nadat je het hebt gerapporteerd.',
    ],

    'volume' => [
        'created' => 'Aangemaakt',
        'resolved' => 'Opgelost',
        'reopened' => 'Heropend',
        'clearance' => 'Wegwerkpercentage',
        'clearance_hint' => 'Opgelost ten opzichte van aangemaakt. Onder de 100% groeit de werkvoorraad.',
        'per_day' => 'Aangemaakt en opgelost, per dag',
        'breakdown' => 'Aangemaakt per :dimension',
    ],

    'workload' => [
        'resolved' => 'Opgelost',
        'agents' => 'Behandelaars',
        'average' => 'Gemiddelde oplostijd',
        'per_agent' => 'Opgelost per behandelaar',
        'note' => 'Geteld bij degene aan wie het ticket was toegewezen op het moment van oplossen.',
    ],

    'cycle_time' => [
        'first_response' => 'Gemiddelde eerste reactie',
        'resolution' => 'Gemiddelde oplostijd',
        'measured' => 'Gemeten tickets',
        'response_per_day' => 'Tijd tot een eerste antwoord',
        'resolution_per_day' => 'Tijd tot een oplossing',
        'breakdown' => 'Oplostijd per :dimension',
        'note' => 'Gemiddeld naar het gedane werk, niet per dag — zo weegt een rustige zondag niet even zwaar als een drukke maandag. De twee staan apart omdat het minuten en dagen zijn: elk zijn eigen as.',
    ],

    'export' => [
        'label' => 'Exporteren',
        'summary' => 'Deze cijfers (CSV)',
        'tickets' => 'De tickets erachter (CSV)',
    ],

    'saved' => [
        'title' => 'Opgeslagen rapporten',
        'save' => 'Dit rapport opslaan',
        'name' => 'Naam',
        'description' => 'Omschrijving',
        'shared' => 'Delen met iedereen',
        'shared_help' => 'Een gedeeld rapport staat op het rapportagescherm van iedereen die rapporten mag lezen.',
        'mine' => 'Van jou',
        'empty' => 'Nog geen opgeslagen rapporten.',
        'empty_hint' => 'Sla een periode en uitsplitsing op die je vaak bekijkt, dan staat hij hier.',
        'created' => 'Opgeslagen als “:name”.',
        'deleted' => 'Opgeslagen rapport verwijderd.',
        'confirm_delete' => 'Dit opgeslagen rapport verwijderen?',
    ],
];
