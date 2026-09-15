<?php

declare(strict_types=1);

return [
    // Laravel's own authentication messages.
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'inactive' => 'This account has been deactivated. Contact an administrator.',

    // Ticktz UI.
    'login' => [
        'title' => 'Sign in',
        'subtitle' => 'Sign in to the service desk.',
        'email' => 'E-mail address or username',
        'password' => 'Password',
        'remember' => 'Keep me signed in',
        'forgot' => 'Forgot your password?',
        'submit' => 'Sign in',
        'directory_hint' => 'Company account holders can sign in with their directory credentials.',
    ],
    'register' => [
        'title' => 'Create an account',
        'subtitle' => 'Register to submit requests through the portal.',
        'name' => 'Full name',
        'email' => 'E-mail address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'submit' => 'Create account',
        'have_account' => 'Already have an account?',
        'disabled' => 'Self-registration is disabled on this instance. Ask an administrator for an account.',
    ],
    'forgot' => [
        'title' => 'Reset your password',
        'subtitle' => 'We will e-mail you a link to choose a new password.',
        'submit' => 'Send reset link',
        'back_to_login' => 'Back to sign in',
    ],
    'reset' => [
        'title' => 'Choose a new password',
        'submit' => 'Reset password',
    ],
    'confirm' => [
        'title' => 'Confirm your password',
        'subtitle' => 'This is a secure area. Please confirm your password before continuing.',
        'submit' => 'Confirm',
    ],
    'verify' => [
        'title' => 'Verify your e-mail address',
        'subtitle' => 'We sent you a verification link. Click it to activate your account.',
        'resend' => 'Resend verification e-mail',
        'sent' => 'A new verification link has been sent to your e-mail address.',
    ],
    'logout' => 'Sign out',
];
