<?php

declare(strict_types=1);

return [

    'title' => 'Ticktz installeren',
    'subtitle' => 'Een paar vragen en deze installatie is klaar voor het eerste ticket.',
    'version' => 'Versie :version',

    'steps' => [
        'welcome' => 'Welkom',
        'requirements' => 'Vereisten',
        'database' => 'Database',
        'application' => 'Applicatie',
        'administrator' => 'Beheerder',
        'mail' => 'E-mail',
        'finish' => 'Afronden',
    ],

    'actions' => [
        'back' => 'Terug',
        'next' => 'Verder',
        'install' => 'Ticktz installeren',
        'installing' => 'Bezig met installeren…',
        'test' => 'Verbinding testen',
        'testing' => 'Bezig met testen…',
        'retry' => 'Opnieuw controleren',
        'skip' => 'Nu overslaan',
    ],

    'welcome' => [
        'heading' => 'Welkom',
        'body' => 'Ticktz is een servicedesk die je zelf host. Alles wat erin staat blijft op je eigen infrastructuur: geen analytics-SDK, geen telemetrie en geen trackers van derden.',
        'what' => 'Deze wizard gaat:',
        'list' => [
            'check' => 'controleren of deze server Ticktz kan draaien',
            'database' => 'verbinden met een database — die van Ticktz zelf, of die van jou',
            'admin' => 'je beheerdersaccount aanmaken',
            'mail' => 'eventueel uitgaande e-mail instellen',
        ],
        'language' => 'Taal',
        'language_help' => 'Voor deze wizard, en de standaard voor nieuwe accounts. Iedereen kiest daarna zijn eigen taal.',
        'time' => 'Het duurt ongeveer twee minuten.',
    ],

    'requirements' => [
        'heading' => 'Vereisten',
        'body' => 'Alles hieronder moet kloppen voordat Ticktz kan starten.',
        'required' => 'Vereist',
        'recommended' => 'Aanbevolen',
        'recommended_help' => 'Elk hiervan is nodig voor één functie. Je kunt zonder installeren en ze later toevoegen — die functie blijft dan simpelweg onbeschikbaar.',
        'ok' => 'Deze server kan Ticktz draaien.',
        'failed' => 'Een aantal vereisten klopt nog niet. Los ze op en controleer opnieuw — de installatie kan pas verder als ze slagen.',
    ],

    'database' => [
        'heading' => 'Database',
        'body' => 'Ticktz bewaart alles in MySQL. Er gaat niets buiten.',

        'bundled' => 'De ingebouwde database gebruiken',
        'bundled_help' => 'De MySQL-server die met Ticktz meekomt en er al naast draait. Niets in te stellen, niets apart te onderhouden.',
        'bundled_unavailable' => 'Er is geen ingebouwde database gevonden. Dat klopt als je Ticktz buiten de meegeleverde Docker-stack draait.',

        'external' => 'Mijn eigen database gebruiken',
        'external_help' => 'Een MySQL- of MariaDB-server die je al draait — beheerd, in een cluster, of gewoon die al in je back-ups zit.',

        'fields' => [
            'host' => 'Host',
            'port' => 'Poort',
            'database' => 'Databasenaam',
            'username' => 'Gebruikersnaam',
            'password' => 'Wachtwoord',
        ],

        'prepare' => 'De database moet al bestaan, en de gebruiker moet er tabellen in mogen aanmaken.',

        'result' => [
            'connected' => 'Verbonden met MySQL :server.',
            'writable' => 'De gebruiker kan tabellen aanmaken.',
            'not_writable' => 'Verbonden, maar deze gebruiker kan geen tabellen aanmaken. Geef CREATE, ALTER, INDEX en DROP op deze database, anders lopen de migraties vast.',
            'empty' => 'De database is leeg en klaar.',
            'not_empty' => 'Deze database bevat al tabellen. Als het een oudere Ticktz is, draaien de migraties eroverheen. Hoort hij bij iets anders, stop dan en kies een andere.',
        ],

        'errors' => [
            'credentials' => 'De server weigerde die gebruikersnaam of dat wachtwoord.',
            'unknown_database' => 'De server is bereikbaar, maar er is geen database met die naam. Maak hem eerst aan — Ticktz doet dat niet voor je.',
            'no_access' => 'De server geeft deze gebruiker die database niet — óf hij bestaat niet, óf de gebruiker heeft er geen rechten op. MySQL zegt niet welke van de twee. Maak de database aan en geef de gebruiker er toegang toe.',
            'unreachable' => 'Er antwoordde niets op die host en poort. Controleer het adres, de poort en alles wat ertussen zit.',
            'unknown_host' => 'Die hostnaam kon niet worden gevonden.',
            'failed' => 'De verbinding is mislukt.',
        ],
    ],

    'application' => [
        'heading' => 'Jouw servicedesk',
        'body' => 'Hoe deze installatie zichzelf voorstelt.',
        'name' => 'Naam',
        'name_help' => 'Zichtbaar in de interface en in uitgaande e-mail.',
        'url' => 'Adres',
        'url_help' => 'Precies zoals mensen erbij komen, inclusief https. Links in notificatiemail worden hiermee gebouwd, dus een verkeerde waarde breekt ze allemaal.',
        'timezone' => 'Tijdzone',
        'timezone_help' => 'Openingstijden en serviceniveaus worden in deze zone gemeten.',
        'locale' => 'Standaardtaal',
        'prefix' => 'Ticketprefix',
        'prefix_help' => 'Het eerste deel van elk ticketnummer, zoals SUP-1042. Hoofdletters en cijfers.',
    ],

    'administrator' => [
        'heading' => 'Jouw account',
        'body' => 'Het eerste account op deze installatie. Het heeft alle rechten, ook die waarmee je de boel kunt slopen, dus geef het een echt wachtwoord.',
        'name' => 'Volledige naam',
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'password_help' => 'Minimaal 12 tekens, met letters en cijfers.',
        'mismatch' => 'De twee wachtwoorden zijn niet gelijk.',
        'confirm' => 'Wachtwoord herhalen',
    ],

    'mail' => [
        'heading' => 'Uitgaande e-mail',
        'body' => 'Ticktz laat mensen weten wanneer hun ticket verandert. Zonder dit werkt het, maar stil.',
        'enable' => 'E-mail nu instellen',
        'later' => 'Je kunt dit later toevoegen bij Beheer → Postbussen, samen met e-mail-naar-ticket.',
        'host' => 'SMTP-server',
        'port' => 'Poort',
        'username' => 'Gebruikersnaam',
        'password' => 'Wachtwoord',
        'scheme' => 'Versleuteling',
        'scheme_none' => 'Geen',
        'from' => 'Versturen als',
        'from_help' => 'Het adres waar notificaties vandaan komen, en waar mensen op antwoorden.',
    ],

    'finish' => [
        'heading' => 'Klaar',
        'body' => 'Loop het nog even na. Installeren schrijft je configuratie weg, maakt de database klaar en maakt je account aan.',
        'database' => 'Database',
        'bundled' => 'Ingebouwd',
        'external' => 'Eigen server',
        'application' => 'Servicedesk',
        'administrator' => 'Beheerder',
        'mail' => 'E-mail',
        'mail_off' => 'Niet ingesteld',
        'warning' => 'Dit is de laatste stap die je nog ongedaan maakt door het tabblad te sluiten.',
    ],

    'done' => [
        'flash' => 'Ticktz is geïnstalleerd. Log in met het account dat je net hebt aangemaakt.',
    ],

    'errors' => [
        'failed' => 'De installatie kon niet worden afgerond. De details staan in het applicatielog.',
    ],

];
