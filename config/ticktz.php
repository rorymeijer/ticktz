<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Product
    |--------------------------------------------------------------------------
    */

    'version' => env('TICKTZ_VERSION', '0.1.0-dev'),

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

    'queues' => [
        'high' => env('TICKTZ_QUEUE_HIGH', 'high'),
        'default' => env('TICKTZ_QUEUE_DEFAULT', 'default'),
        'mail' => env('TICKTZ_QUEUE_MAIL', 'mail'),
        'webhooks' => env('TICKTZ_QUEUE_WEBHOOKS', 'webhooks'),
        'low' => env('TICKTZ_QUEUE_LOW', 'low'),
    ],

];
