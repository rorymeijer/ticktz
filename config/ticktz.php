<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Product
    |--------------------------------------------------------------------------
    */

    'version' => env('TICKTZ_VERSION', '1.1.0'),

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
    | Images pasted into rich text
    |--------------------------------------------------------------------------
    |
    | Separate limits from attachments on purpose. A screenshot pasted into a
    | reply is inline in somebody's reading, so it wants to be small; a 25MB
    | attachment is a file somebody chose to send. Stored on the same disk by
    | default, and never on a public one.
    |
    */
    'rich_text' => [
        'images' => [
            'disk' => env('TICKTZ_RICH_TEXT_IMAGE_DISK', env('TICKTZ_ATTACHMENT_DISK', 'local')),
            'max_kilobytes' => (int) env('TICKTZ_MAX_INLINE_IMAGE_KB', 5120),
            'extensions' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('TICKTZ_ALLOWED_INLINE_IMAGE_EXTENSIONS', 'png,jpg,jpeg,gif,webp')),
            ))),
            // How long an image nobody ever saved is kept before the prune
            // command sweeps it up. Long enough to survive a draft somebody
            // left open over a weekend.
            'orphan_days' => (int) env('TICKTZ_INLINE_IMAGE_ORPHAN_DAYS', 7),
        ],
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
        'uploads' => (int) env('TICKTZ_UPLOAD_RATE_LIMIT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Updates
    |--------------------------------------------------------------------------
    |
    | Off. This is the only outbound request Ticktz would make that nobody
    | configured — no mailbox, no webhook, no directory, just the application
    | talking to a third party — and the brief this was built to says no
    | telemetry and no third-party trackers. Turning it on sends nothing about
    | the desk: it is the same unauthenticated request a browser makes for a
    | public release list, and what GitHub learns is this server's IP address.
    |
    | With it off, the update screen still works. It shows the running version
    | and how to upgrade; it just cannot tell you whether there is a newer one.
    |
    */
    'updates' => [
        'enabled' => (bool) env('TICKTZ_UPDATE_CHECK', false),
        'repository' => (string) env('TICKTZ_UPDATE_REPOSITORY', 'rorymeijer/ticktz'),
        'pre_releases' => (bool) env('TICKTZ_UPDATE_PRE_RELEASES', false),
        'cache_hours' => (int) env('TICKTZ_UPDATE_CACHE_HOURS', 6),
        'timeout' => (int) env('TICKTZ_UPDATE_TIMEOUT', 8),

        /*
         * Where this instance keeps its code, and whether it may replace it.
         *
         * A source install can upgrade itself: fetch the release, install it,
         * migrate. A Docker install cannot — replacing a running container
         * needs the daemon, and a web-facing PHP process must never be able to
         * reach that — so it is shown the command instead.
         *
         * `self_upgrade` is the switch, and it is not the only gate: the
         * process doing the work has to own the code, and the process serving
         * the web must not. See UpgradePreflight.
         */
        'self_upgrade' => (bool) env('TICKTZ_SELF_UPGRADE', false),
        'root' => (string) env('TICKTZ_INSTALL_ROOT', base_path()),

        /*
         * Room a release needs beside the installation. A flat floor: a
         * release is a production tree, a few hundred megabytes, archive and
         * extraction together well inside a gigabyte. The exact check happens
         * against the release asset's own size, where the real number is.
         */
        'free_bytes_required' => (int) env('TICKTZ_UPDATE_FREE_BYTES', 1024 * 1024 * 1024),
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
