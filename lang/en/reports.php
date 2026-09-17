<?php

declare(strict_types=1);

return [
    'title' => 'Reports',
    'subtitle' => 'What the desk did, over a period you choose.',
    'no_data' => 'Nothing measured in this period.',
    'unassigned' => 'Unassigned',

    'reports' => [
        'sla' => 'SLA compliance',
        'volume' => 'Ticket volume',
        'workload' => 'Agent workload',
        'cycle_time' => 'How long it takes',
    ],

    'descriptions' => [
        'sla' => 'Of the SLA clocks that finished in this period, how many were met.',
        'volume' => 'How much arrived, and how much the desk cleared.',
        'workload' => 'Who resolved what, and how long they took over it.',
        'cycle_time' => 'Time to a first reply, and time to a resolution.',
    ],

    'filters' => [
        'from' => 'From',
        'to' => 'To',
        'dimension' => 'Split by',
        'apply' => 'Apply',
        'presets' => [
            'week' => 'Last 7 days',
            'month' => 'Last 30 days',
            'quarter' => 'Last 90 days',
            'year' => 'Last 12 months',
        ],
    ],

    'dimensions' => [
        'all' => 'Nothing',
        'queue' => 'Queue',
        'team' => 'Team',
        'assignee' => 'Agent',
        'priority' => 'Priority',
        'request_type' => 'Request type',
        'organization' => 'Organisation',
    ],

    'sla' => [
        'title' => 'SLA compliance',
        'compliance' => 'Compliance',
        'met' => 'Met',
        'breached' => 'Missed',
        'first_response' => 'First response',
        'resolution' => 'Resolution',
        'outcomes' => 'Clocks finished',
        'per_day' => 'Met and missed, per day',
        'breakdown' => 'Compliance by :dimension',
        'note' => 'An SLA clock counts on the day it finished, not the day the ticket was raised — so a figure, once reported, does not move.',
    ],

    'volume' => [
        'created' => 'Created',
        'resolved' => 'Resolved',
        'reopened' => 'Reopened',
        'clearance' => 'Clearance',
        'clearance_hint' => 'Resolved as a share of created. Under 100% the backlog is growing.',
        'per_day' => 'Created and resolved, per day',
        'breakdown' => 'Created by :dimension',
    ],

    'workload' => [
        'resolved' => 'Resolved',
        'agents' => 'Agents',
        'average' => 'Average time to resolve',
        'per_agent' => 'Resolved per agent',
        'note' => 'Counted against whoever the ticket was assigned to when it was resolved.',
    ],

    'cycle_time' => [
        'first_response' => 'Average first response',
        'resolution' => 'Average resolution',
        'measured' => 'Tickets measured',
        'response_per_day' => 'Time to a first reply',
        'resolution_per_day' => 'Time to a resolution',
        'breakdown' => 'Resolution time by :dimension',
        'note' => 'Averaged by the work done, not by the day — so a quiet Sunday does not weigh as much as a busy Monday. The two are drawn separately because they are minutes and days: one axis each.',
    ],

    'export' => [
        'label' => 'Export',
        'summary' => 'These figures (CSV)',
        'tickets' => 'The tickets behind them (CSV)',
    ],

    'saved' => [
        'title' => 'Saved reports',
        'save' => 'Save this report',
        'name' => 'Name',
        'description' => 'Description',
        'shared' => 'Share with everybody',
        'shared_help' => 'A shared report is on the reporting screen for everybody who may read reports.',
        'mine' => 'Yours',
        'empty' => 'No saved reports yet.',
        'empty_hint' => 'Save a period and a split you look at often, and it turns up here.',
        'created' => 'Saved as “:name”.',
        'deleted' => 'Saved report removed.',
        'confirm_delete' => 'Remove this saved report?',
    ],
];
