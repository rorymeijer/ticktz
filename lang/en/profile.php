<?php

declare(strict_types=1);

return [
    'title' => 'Profile',
    'information' => [
        'title' => 'Account information',
        'description' => 'Update your name, e-mail address and interface language.',
        'name' => 'Full name',
        'email' => 'E-mail address',
        'locale' => 'Interface language',
        'locale_help' => 'Applies to the web interface and to notification e-mails.',
        'saved' => 'Profile updated.',
        'managed_by_directory' => 'Your name and e-mail address are managed by the directory and cannot be changed here.',
    ],
    'password' => [
        'title' => 'Change password',
        'description' => 'Use a long, unique password.',
        'current' => 'Current password',
        'new' => 'New password',
        'confirm' => 'Confirm new password',
        'saved' => 'Password changed.',
    ],
    'sessions' => [
        'title' => 'Signed-in devices',
    ],
    'delete' => [
        'title' => 'Delete account',
        'description' => 'Permanently remove your account. Tickets you created stay in the system, attributed to a deleted user.',
        'confirm_title' => 'Delete your account?',
        'confirm_description' => 'Enter your password to confirm.',
        'submit' => 'Delete account',
    ],
];
