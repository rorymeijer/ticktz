<?php

declare(strict_types=1);

return [

    'title' => 'Set up Ticktz',
    'subtitle' => 'A few questions, and this instance is ready to take its first ticket.',
    'version' => 'Version :version',

    'steps' => [
        'welcome' => 'Welcome',
        'requirements' => 'Requirements',
        'database' => 'Database',
        'application' => 'Application',
        'administrator' => 'Administrator',
        'mail' => 'E-mail',
        'finish' => 'Finish',
    ],

    'actions' => [
        'back' => 'Back',
        'next' => 'Continue',
        'install' => 'Install Ticktz',
        'installing' => 'Installing…',
        'test' => 'Test connection',
        'testing' => 'Testing…',
        'retry' => 'Check again',
        'skip' => 'Skip for now',
    ],

    'welcome' => [
        'heading' => 'Welcome',
        'body' => 'Ticktz is a self-hosted service desk. Everything it stores stays on your own infrastructure: there is no analytics SDK, no telemetry and no third-party tracker anywhere in it.',
        'what' => 'This wizard will:',
        'list' => [
            'check' => 'check that this server can run Ticktz',
            'database' => 'connect it to a database — the one that ships with it, or your own',
            'admin' => 'create your administrator account',
            'mail' => 'optionally set up outgoing e-mail',
        ],
        'language' => 'Language',
        'language_help' => 'For this wizard, and the default for new accounts. Everyone picks their own afterwards.',
        'time' => 'It takes about two minutes.',
    ],

    'requirements' => [
        'heading' => 'Requirements',
        'body' => 'Everything below has to be true before Ticktz can start.',
        'required' => 'Required',
        'recommended' => 'Recommended',
        'recommended_help' => 'Each of these is needed by one feature. You can install without them and add them later — the feature simply stays unavailable.',
        'ok' => 'This server can run Ticktz.',
        'failed' => 'Some requirements are not met. Fix them and check again — the installer cannot continue until they pass.',
    ],

    'database' => [
        'heading' => 'Database',
        'body' => 'Ticktz keeps everything in MySQL. Nothing leaves it.',

        'bundled' => 'Use the built-in database',
        'bundled_help' => 'The MySQL server that ships with Ticktz, already running alongside it. Nothing to configure, nothing to maintain separately.',
        'bundled_unavailable' => 'No built-in database was detected. That is expected when you run Ticktz outside its Docker stack.',

        'external' => 'Use my own database',
        'external_help' => 'A MySQL or MariaDB server you already run — managed, clustered, or simply the one that is already in your backups.',

        'fields' => [
            'host' => 'Host',
            'port' => 'Port',
            'database' => 'Database name',
            'username' => 'Username',
            'password' => 'Password',
        ],

        'prepare' => 'The database must already exist, and the user needs permission to create tables in it.',

        'result' => [
            'connected' => 'Connected to MySQL :server.',
            'writable' => 'The user can create tables.',
            'not_writable' => 'Connected, but this user cannot create tables. Grant it CREATE, ALTER, INDEX and DROP on this database, or the migrations will fail.',
            'empty' => 'The database is empty and ready.',
            'not_empty' => 'This database already contains tables. If it is an older Ticktz, installing will run migrations over it. If it belongs to something else, stop and pick another.',
        ],

        'errors' => [
            'credentials' => 'The server rejected that username or password.',
            'unknown_database' => 'The server is reachable, but there is no database with that name. Create it first — Ticktz will not create it for you.',
            'no_access' => 'The server will not give this user that database — either it does not exist, or the user has no rights to it. MySQL does not say which. Create the database and grant the user access to it.',
            'unreachable' => 'Nothing answered on that host and port. Check the address, the port and anything between them.',
            'unknown_host' => 'That hostname could not be resolved.',
            'failed' => 'The connection failed.',
        ],
    ],

    'application' => [
        'heading' => 'Your service desk',
        'body' => 'How this instance introduces itself.',
        'name' => 'Name',
        'name_help' => 'Shown in the interface and in outgoing e-mail.',
        'url' => 'Address',
        'url_help' => 'Exactly how people reach it, including https. Links in notification e-mail are built from this, so a wrong value here breaks all of them.',
        'timezone' => 'Time zone',
        'timezone_help' => 'Business hours and service levels are measured in this zone.',
        'locale' => 'Default language',
        'prefix' => 'Ticket prefix',
        'prefix_help' => 'The first part of every ticket number, like SUP-1042. Capitals and digits.',
    ],

    'administrator' => [
        'heading' => 'Your account',
        'body' => 'The first account on this instance. It has every permission, including the ones that can take the instance apart, so give it a real password.',
        'name' => 'Full name',
        'email' => 'E-mail address',
        'password' => 'Password',
        'password_help' => 'At least 12 characters, with letters and numbers.',
        'mismatch' => 'The two passwords do not match.',
        'confirm' => 'Repeat password',
    ],

    'mail' => [
        'heading' => 'Outgoing e-mail',
        'body' => 'Ticktz notifies people when their ticket moves. Without this it works, quietly.',
        'enable' => 'Set up e-mail now',
        'later' => 'You can add this later under Administration → Mailboxes, along with e-mail-to-ticket.',
        'host' => 'SMTP server',
        'port' => 'Port',
        'username' => 'Username',
        'password' => 'Password',
        'scheme' => 'Encryption',
        'scheme_none' => 'None',
        'from' => 'Send from',
        'from_help' => 'The address notifications come from, and the one people reply to.',
    ],

    'finish' => [
        'heading' => 'Ready',
        'body' => 'Check it over. Installing writes your configuration, prepares the database and creates your account.',
        'database' => 'Database',
        'bundled' => 'Built-in',
        'external' => 'Your own server',
        'application' => 'Service desk',
        'administrator' => 'Administrator',
        'mail' => 'E-mail',
        'mail_off' => 'Not configured',
        'warning' => 'This is the last step that can be undone by closing the tab.',
    ],

    'done' => [
        'flash' => 'Ticktz is installed. Sign in with the account you just created.',
    ],

    'errors' => [
        'failed' => 'The installation could not be completed. The details are in the application log.',
    ],

];
