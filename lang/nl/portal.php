<?php

declare(strict_types=1);

return [
    'welcome' => [
        'title' => 'Waarmee kunnen we helpen?',
        'subtitle' => 'Dien een verzoek in, volg de voortgang en doorzoek de kennisbank.',
        'search_placeholder' => 'Zoek in de kennisbank of omschrijf je vraag…',
        'browse' => 'Bekijk aanvraagtypen',
        'sign_in_prompt' => 'Log in om een verzoek in te dienen en je tickets te volgen.',
    ],
    'landing' => [
        'tagline' => 'Self-hosted servicedesk',
        'intro' => 'Ticktz is een open-source servicedesk die je zelf draait: tickets, SLA’s, automatisering, een klantportaal en een kennisbank — allemaal in je eigen database.',
        'cta_portal' => 'Naar het portaal',
        'cta_login' => 'Inloggen',
        'feature_tickets_title' => 'Ticketing voor serviceteams',
        'feature_tickets_body' => 'Wachtrijen, workflows, interne notities, bijlagen en een volledig audit-spoor.',
        'feature_sla_title' => 'SLA’s die kantooruren respecteren',
        'feature_sla_body' => 'Business-kalenders, pauzecondities, breach-detectie en escalaties.',
        'feature_privacy_title' => 'Je data blijft van jou',
        'feature_privacy_body' => 'Geen telemetrie, geen third-party trackers, geen cloud lock-in. Eén compose-bestand.',
    ],

    'nav' => [
        'browse' => 'Verzoeken bekijken',
        'my_requests' => 'Mijn verzoeken',
        'new_request' => 'Nieuw verzoek',
    ],

    'home' => [
        'categories' => 'Waar kunnen we mee helpen?',
        'uncategorised' => 'Overige verzoeken',
        'recent' => 'Je recente verzoeken',
        'view_all' => 'Alle verzoeken bekijken',
        'no_request_types' => 'Er zijn nog geen aanvraagtypen gepubliceerd. Neem contact op met de servicedesk.',
    ],

    'form' => [
        'submit' => 'Verzoek indienen',
        'subject' => 'Samenvatting',
        'subject_placeholder' => 'Eén regel die omschrijft wat je nodig hebt',
        'description' => 'Toelichting',
        'description_placeholder' => 'Alles wat helpt om dit sneller op te pakken',
        'priority' => 'Hoe urgent is dit?',
        'attachments' => 'Bijlagen',
        'attachments_help' => 'Schermafbeeldingen of documenten die het verzoek verduidelijken.',
        'required_hint' => 'Velden met * zijn verplicht.',
        'select_placeholder' => 'Kies…',
    ],

    'requests' => [
        'title' => 'Mijn verzoeken',
        'subtitle' => 'Alles wat je ons gevraagd hebt, en hoe het ervoor staat.',
        'filters' => [
            'all' => 'Alle',
            'open' => 'Open',
            'closed' => 'Gesloten',
        ],
        'columns' => [
            'request' => 'Verzoek',
            'status' => 'Status',
            'updated' => 'Laatste update',
        ],
        'submitted_by' => 'Ingediend door :name',
        'empty' => 'Je hebt nog geen verzoeken ingediend.',
        'empty_action' => 'Dien je eerste verzoek in',
        'opened_on' => 'Geopend op :date',
        'handled_by' => 'Behandeld door :name',
        'unassigned' => 'Nog niet opgepakt',
        'details' => 'Gegevens die je hebt ingevuld',
        'conversation' => 'Gesprek',
        'reply_placeholder' => 'Voeg informatie toe of beantwoord een vraag…',
        'reply' => 'Versturen',
        'closed_notice' => 'Dit verzoek is gesloten. Reageren heropent het.',
        'you' => 'Jij',
    ],

    'flash' => [
        'submitted' => 'Je verzoek is ingediend als :key.',
        'replied' => 'Je reactie is toegevoegd.',
    ],
];
