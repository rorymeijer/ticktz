<?php

declare(strict_types=1);

return [
    'welcome' => [
        'title' => 'How can we help?',
        'subtitle' => 'Submit a request, follow its progress and search the knowledge base.',
        'search_placeholder' => 'Search the knowledge base or describe your issue…',
        'browse' => 'Browse request types',
        'sign_in_prompt' => 'Sign in to submit a request and follow your tickets.',
    ],
    'landing' => [
        'tagline' => 'Self-hosted service desk',
        'intro' => 'Ticktz is an open-source service desk you run yourself: tickets, SLAs, automation, a customer portal and a knowledge base — all in your own database.',
        'cta_portal' => 'Go to the portal',
        'cta_login' => 'Sign in',
        'feature_tickets_title' => 'Ticketing built for service teams',
        'feature_tickets_body' => 'Queues, workflows, internal notes, attachments and a complete audit trail.',
        'feature_sla_title' => 'SLAs that respect office hours',
        'feature_sla_body' => 'Business calendars, pause conditions, breach detection and escalations.',
        'feature_privacy_title' => 'Your data stays yours',
        'feature_privacy_body' => 'No telemetry, no third-party trackers, no cloud lock-in. One compose file.',
    ],

    'nav' => [
        'browse' => 'Browse requests',
        'my_requests' => 'My requests',
        'new_request' => 'New request',
    ],

    'home' => [
        'categories' => 'What do you need?',
        'uncategorised' => 'Other requests',
        'recent' => 'Your recent requests',
        'view_all' => 'View all requests',
        'no_request_types' => 'No request types have been published yet. Contact your service desk.',
    ],

    'form' => [
        'submit' => 'Submit request',
        'subject' => 'Summary',
        'subject_placeholder' => 'One line describing what you need',
        'description' => 'Details',
        'description_placeholder' => 'Anything that helps us pick this up faster',
        'priority' => 'How urgent is this?',
        'attachments' => 'Attachments',
        'attachments_help' => 'Screenshots or documents that help us understand the request.',
        'required_hint' => 'Fields marked * are required.',
        'select_placeholder' => 'Choose…',
    ],

    'requests' => [
        'title' => 'My requests',
        'subtitle' => 'Everything you have asked us, and where it stands.',
        'filters' => [
            'all' => 'All',
            'open' => 'Open',
            'closed' => 'Closed',
        ],
        'columns' => [
            'request' => 'Request',
            'status' => 'Status',
            'updated' => 'Last update',
        ],
        'submitted_by' => 'Submitted by :name',
        'empty' => 'You have not submitted any requests yet.',
        'empty_action' => 'Submit your first request',
        'opened_on' => 'Opened on :date',
        'handled_by' => 'Handled by :name',
        'unassigned' => 'Not yet picked up',
        'details' => 'Details you provided',
        'conversation' => 'Conversation',
        'reply_placeholder' => 'Add more information or answer a question…',
        'reply' => 'Send',
        'closed_notice' => 'This request is closed. Reply to reopen it.',
        'you' => 'You',
    ],

    'flash' => [
        'submitted' => 'Your request has been submitted as :key.',
        'replied' => 'Your reply has been added.',
    ],
];
