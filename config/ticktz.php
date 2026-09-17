<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Product
    |--------------------------------------------------------------------------
    */

    'version' => env('TICKTZ_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Supported UI locales
    |--------------------------------------------------------------------------
    |
    | Every locale listed here must have a matching directory under lang/.
    | Users pick their own locale in their profile; the app falls back to
    | APP_LOCALE and then APP_FALLBACK_LOCALE.
    |
    */

    'locales' => [
        'en' => ['name' => 'English', 'native' => 'English'],
        'nl' => ['name' => 'Dutch', 'native' => 'Nederlands'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    'attachments' => [
        'disk' => env('TICKTZ_ATTACHMENT_DISK', 'local'),
        'max_kilobytes' => (int) env('TICKTZ_MAX_ATTACHMENT_KB', 25600),
        'allowed_extensions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'TICKTZ_ALLOWED_ATTACHMENT_EXTENSIONS',
                'pdf,png,jpg,jpeg,gif,webp,txt,csv,log,doc,docx,xls,xlsx,ppt,pptx,odt,ods,zip,eml,msg'
            ))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (requests per minute)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'login' => (int) env('TICKTZ_LOGIN_RATE_LIMIT', 5),
        'portal' => (int) env('TICKTZ_PORTAL_RATE_LIMIT', 60),
        'api' => (int) env('TICKTZ_API_RATE_LIMIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination defaults
    |--------------------------------------------------------------------------
    */

    'per_page' => [
        'tickets' => (int) env('TICKTZ_PER_PAGE_TICKETS', 25),
        'admin' => (int) env('TICKTZ_PER_PAGE_ADMIN', 25),
        'portal' => (int) env('TICKTZ_PER_PAGE_PORTAL', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue names
    |--------------------------------------------------------------------------
    |
    | Background work is split across queues so a slow mailbox poll can never
    | starve SLA breach detection. Keep these in sync with TICKTZ_QUEUES.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Automation
    |--------------------------------------------------------------------------
    |
    | How far a chain of rules reacting to rules may run before it is cut off.
    | A rule already runs at most once per ticket per cascade, so this is the
    | backstop rather than the main defence — but a runaway automation is the
    | one failure mode of this feature that can take an instance down.
    |
    */

    'automation' => [
        'max_depth' => (int) env('TICKTZ_AUTOMATION_MAX_DEPTH', 5),
        // Executions older than this are pruned nightly. The log is written on
        // every evaluation, including the skips, so it grows quickly.
        'log_retention_days' => (int) env('TICKTZ_AUTOMATION_LOG_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | An approver can answer from their inbox without signing in. The link they
    | click carries a bearer token, so it is hashed at rest, single-use, and
    | expires — an approve link that works forever is a key sitting in an inbox
    | forever. Shorten this on an instance where mailboxes are shared.
    |
    | `decide_by_email` turns the links off entirely: the notification then
    | says what is waiting and links to the portal, where the approver signs
    | in. That is the right setting for a desk approving things that matter.
    |
    */

    'approvals' => [
        'decide_by_email' => (bool) env('TICKTZ_APPROVAL_EMAIL_LINKS', true),
        'token_days' => (int) env('TICKTZ_APPROVAL_TOKEN_DAYS', 30),
        // Reminder cadence for an approval nobody has answered, in hours.
        // Zero switches reminders off.
        'reminder_hours' => (int) env('TICKTZ_APPROVAL_REMINDER_HOURS', 24),
    ],

    'queues' => [
        'high' => env('TICKTZ_QUEUE_HIGH', 'high'),
        'default' => env('TICKTZ_QUEUE_DEFAULT', 'default'),
        'mail' => env('TICKTZ_QUEUE_MAIL', 'mail'),
        'webhooks' => env('TICKTZ_QUEUE_WEBHOOKS', 'webhooks'),
        'low' => env('TICKTZ_QUEUE_LOW', 'low'),
    ],

];
