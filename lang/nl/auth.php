<?php

declare(strict_types=1);

return [
    'failed' => 'Deze gegevens komen niet overeen met onze administratie.',
    'password' => 'Het opgegeven wachtwoord is onjuist.',
    'throttle' => 'Te veel inlogpogingen. Probeer het over :seconds seconden opnieuw.',
    'inactive' => 'Dit account is gedeactiveerd. Neem contact op met een beheerder.',

    'login' => [
        'title' => 'Inloggen',
        'subtitle' => 'Log in op de servicedesk.',
        'email' => 'E-mailadres of gebruikersnaam',
        'password' => 'Wachtwoord',
        'remember' => 'Ingelogd blijven',
        'forgot' => 'Wachtwoord vergeten?',
        'submit' => 'Inloggen',
        'directory_hint' => 'Medewerkers kunnen inloggen met hun netwerkaccount.',
    ],
    'register' => [
        'title' => 'Account aanmaken',
        'subtitle' => 'Registreer om verzoeken via het portaal in te dienen.',
        'name' => 'Volledige naam',
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'password_confirmation' => 'Bevestig wachtwoord',
        'submit' => 'Account aanmaken',
        'have_account' => 'Heb je al een account?',
        'disabled' => 'Zelfregistratie staat uit op deze omgeving. Vraag een beheerder om een account.',
    ],
    'forgot' => [
        'title' => 'Wachtwoord herstellen',
        'subtitle' => 'We mailen je een link om een nieuw wachtwoord te kiezen.',
        'submit' => 'Herstellink versturen',
        'back_to_login' => 'Terug naar inloggen',
    ],
    'reset' => [
        'title' => 'Kies een nieuw wachtwoord',
        'submit' => 'Wachtwoord herstellen',
    ],
    'confirm' => [
        'title' => 'Bevestig je wachtwoord',
        'subtitle' => 'Dit is een beveiligd gedeelte. Bevestig je wachtwoord om verder te gaan.',
        'submit' => 'Bevestigen',
    ],
    'verify' => [
        'title' => 'Bevestig je e-mailadres',
        'subtitle' => 'We hebben je een verificatielink gestuurd. Klik erop om je account te activeren.',
        'resend' => 'Verificatiemail opnieuw versturen',
        'sent' => 'Er is een nieuwe verificatielink naar je e-mailadres gestuurd.',
    ],
    'logout' => 'Uitloggen',
];
