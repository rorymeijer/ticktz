<?php

declare(strict_types=1);

return [
    'footer' => [
        'reply_hint' => 'Beantwoord deze e-mail om informatie aan je verzoek toe te voegen — je reactie komt automatisch bij het ticket te staan.',
    ],

    'templates' => [
        'ticket.created.requester' => [
            'subject' => '[{{ ticket.key }}] {{ ticket.subject }}',
            'body' => "Beste {{ requester.first_name }},\n\nWe hebben je verzoek ontvangen en vastgelegd als {{ ticket.key }}.\n\n{{ ticket.description }}\n\nJe kunt het hier volgen: {{ ticket.portal_url }}",
        ],
        'ticket.created.agent' => [
            'subject' => '[{{ ticket.key }}] Nieuw: {{ ticket.subject }}',
            'body' => "Er is een nieuw ticket binnengekomen in {{ ticket.queue }}.\n\nVan: {{ requester.name }} ({{ requester.email }})\nPrioriteit: {{ ticket.priority }}\n\n{{ ticket.description }}\n\n{{ ticket.agent_url }}",
        ],
        'ticket.replied.requester' => [
            'subject' => 'Re: [{{ ticket.key }}] {{ ticket.subject }}',
            'body' => "Beste {{ requester.first_name }},\n\n{{ comment.author }} heeft gereageerd op je verzoek:\n\n{{ comment.body }}\n\nVolg het verzoek hier: {{ ticket.portal_url }}",
        ],
        'ticket.replied.agent' => [
            'subject' => 'Re: [{{ ticket.key }}] {{ ticket.subject }}',
            'body' => "{{ comment.author }} heeft gereageerd op {{ ticket.key }}:\n\n{{ comment.body }}\n\n{{ ticket.agent_url }}",
        ],
        'ticket.assigned.agent' => [
            'subject' => '[{{ ticket.key }}] Aan jou toegewezen: {{ ticket.subject }}',
            'body' => "{{ ticket.key }} is aan jou toegewezen.\n\nMelder: {{ requester.name }}\nPrioriteit: {{ ticket.priority }}\nStatus: {{ ticket.status }}\n\n{{ ticket.agent_url }}",
        ],
        'ticket.resolved.requester' => [
            'subject' => '[{{ ticket.key }}] Opgelost: {{ ticket.subject }}',
            'body' => "Beste {{ requester.first_name }},\n\nJe verzoek {{ ticket.key }} is als opgelost gemarkeerd.\n\n{{ comment.body }}\n\nIs het probleem niet verholpen? Beantwoord deze e-mail en het verzoek gaat weer open.",
        ],
        'approval.requested' => [
            'subject' => '[{{ ticket.key }}] Goedkeuring nodig: {{ approval.subject }}',
            'body' => "Hallo {{ recipient.first_name }},\n\nEr is goedkeuring van je nodig op {{ ticket.key }}.\n\n{{ approval.subject }}\n{{ approval.reason }}\n\nAangevraagd door: {{ requester.name }}\n\n{{ approval.decide_url }}\n\nOf log in en beantwoord het daar: {{ approval.url }}",
        ],
        'approval.reminder' => [
            'subject' => '[{{ ticket.key }}] Herinnering: goedkeuring nodig',
            'body' => "Hallo {{ recipient.first_name }},\n\n{{ ticket.key }} wacht nog steeds op jouw goedkeuring.\n\n{{ approval.subject }}\n\n{{ approval.decide_url }}\n\nOf log in en beantwoord het daar: {{ approval.url }}",
        ],
        'approval.approved' => [
            'subject' => '[{{ ticket.key }}] Goedgekeurd: {{ approval.subject }}',
            'body' => "Hallo {{ recipient.first_name }},\n\n{{ approval.subject }} is goedgekeurd, dus {{ ticket.key }} kan door.\n\n{{ ticket.portal_url }}",
        ],
        'approval.rejected' => [
            'subject' => '[{{ ticket.key }}] Afgewezen: {{ approval.subject }}',
            'body' => "Hallo {{ recipient.first_name }},\n\n{{ approval.subject }} is afgewezen, dus {{ ticket.key }} gaat niet door.\n\n{{ ticket.portal_url }}",
        ],
    ],

];
