<?php

declare(strict_types=1);

return [
    'footer' => [
        'reply_hint' => 'Reply to this e-mail to add information to your request — your reply is added to the ticket automatically.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Packaged notification templates
    |--------------------------------------------------------------------------
    |
    | Used when no template has been configured for a notification. An
    | administrator can override any of these per mailbox and per language in
    | Settings -> Mailboxes; these are the fallback so a fresh instance sends
    | something sensible on day one.
    |
    | Placeholders are {{ token }}; unknown tokens render as an empty string.
    |
    */
    'templates' => [
        'ticket.created.requester' => [
            'subject' => '[{{ ticket.key }}] {{ ticket.subject }}',
            'body' => "Hello {{ requester.first_name }},\n\nWe received your request and it has been logged as {{ ticket.key }}.\n\n{{ ticket.description }}\n\nYou can follow it here: {{ ticket.portal_url }}",
        ],
        'ticket.created.agent' => [
            'subject' => '[{{ ticket.key }}] New: {{ ticket.subject }}',
            'body' => "A new ticket arrived in {{ ticket.queue }}.\n\nFrom: {{ requester.name }} ({{ requester.email }})\nPriority: {{ ticket.priority }}\n\n{{ ticket.description }}\n\n{{ ticket.agent_url }}",
        ],
        'ticket.replied.requester' => [
            'subject' => 'Re: [{{ ticket.key }}] {{ ticket.subject }}',
            'body' => "Hello {{ requester.first_name }},\n\n{{ comment.author }} replied to your request:\n\n{{ comment.body }}\n\nFollow the request here: {{ ticket.portal_url }}",
        ],
        'ticket.replied.agent' => [
            'subject' => 'Re: [{{ ticket.key }}] {{ ticket.subject }}',
            'body' => "{{ comment.author }} added a reply to {{ ticket.key }}:\n\n{{ comment.body }}\n\n{{ ticket.agent_url }}",
        ],
        'ticket.assigned.agent' => [
            'subject' => '[{{ ticket.key }}] Assigned to you: {{ ticket.subject }}',
            'body' => "{{ ticket.key }} has been assigned to you.\n\nRequester: {{ requester.name }}\nPriority: {{ ticket.priority }}\nStatus: {{ ticket.status }}\n\n{{ ticket.agent_url }}",
        ],
        'ticket.resolved.requester' => [
            'subject' => '[{{ ticket.key }}] Resolved: {{ ticket.subject }}',
            'body' => "Hello {{ requester.first_name }},\n\nYour request {{ ticket.key }} has been marked as resolved.\n\n{{ comment.body }}\n\nIf the problem is not solved, reply to this e-mail and the request reopens.",
        ],
    ],

];
