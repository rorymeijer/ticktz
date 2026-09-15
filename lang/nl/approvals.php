<?php

declare(strict_types=1);

return [
    'title' => 'Goedkeuringen',
    'subtitle' => 'Aanvragen die wachten tot iemand ja zegt.',
    'inbox' => 'Wacht op jou',
    'empty' => 'Er wacht niets op jou.',
    'empty_hint' => 'Goedkeuringen die aan jou gevraagd worden, komen hier en in je mailbox binnen.',
    'empty_all' => 'Er staan geen goedkeuringen open.',

    'filters' => [
        'mine' => 'Wacht op mij',
        'all' => 'Alle openstaande goedkeuringen',
    ],

    'status' => [
        'pending' => 'Wacht',
        'approved' => 'Goedgekeurd',
        'rejected' => 'Afgewezen',
        'cancelled' => 'Ingetrokken',
    ],

    'decision' => [
        'pending' => 'Niet beantwoord',
        'approved' => 'Goedgekeurd',
        'rejected' => 'Afgewezen',
        'skipped' => 'Niet meer nodig',
    ],

    'steps' => [
        'title' => 'Stappen',
        'number' => 'Stap :number',
        'state' => [
            'open' => 'Wacht',
            'waiting' => 'Nog niet begonnen',
            'approved' => 'Klaar',
            'rejected' => 'Geweigerd',
            'cancelled' => 'Ingetrokken',
        ],
    ],

    'mode' => [
        'any' => 'Eén van hen beslist',
        'all' => 'Iedereen moet akkoord zijn',
        'any_short' => 'Eén van hen',
        'all_short' => 'Iedereen',
    ],

    'shape' => [
        'single' => 'Eén fiatteur',
        'parallel' => 'Parallel',
        'sequential' => 'Opeenvolgend',
        'empty' => 'Nog geen stappen',
    ],

    'approver_type' => [
        'users' => 'Specifieke personen',
        'team' => 'Iedereen in een team',
        'role' => 'Iedereen met een rol',
        'manager' => 'De leidinggevende van de melder',
        'field' => 'Wie het formulier noemt',
    ],

    'actions' => [
        'approve' => 'Goedkeuren',
        'reject' => 'Afwijzen',
        'cancel' => 'Intrekken',
        'request' => 'Goedkeuring vragen',
        'decide' => 'Beantwoorden',
    ],

    'fields' => [
        'subject' => 'Waar het over gaat',
        'reason' => 'Waarom',
        'comment' => 'Je toelichting',
        'comment_help' => 'Optioneel bij goedkeuren. Bij afwijzen de moeite waard — de melder leest het.',
        'workflow' => 'Goedkeuringsworkflow',
        'workflow_none' => 'Vraag het specifieke personen',
        'approvers' => 'Wie moet antwoorden',
        'due_hours' => 'Antwoord binnen (uren)',
        'due_hours_help' => 'Daarna gemeld als te laat. Er wordt niets automatisch besloten.',
        'due_within' => 'binnen :hours u',
        'approver_field' => 'Formulierveld met de fiatteur',
        'approver_field_help' => 'De sleutel van een gebruikers- of e-mailveld op het aanvraagformulier.',
        'step_name' => 'Naam van de stap',
        'requires_approval' => 'Eerst goedkeuring nodig',
        'requires_approval_help' => 'Deze overgang wordt geweigerd zolang er geen goedkeuring is gegeven — ook als er helemaal geen goedkeuring is aangevraagd.',
        'manager' => 'Leidinggevende',
        'manager_help' => 'Gebruikt door goedkeuringsstappen die om “de leidinggevende van de melder” vragen.',
        'manager_none' => 'Niemand',
    ],

    'ticket' => [
        'title' => 'Goedkeuring',
        'none' => 'Geen goedkeuring op dit ticket.',
        'blocked' => 'Dit ticket wacht op een goedkeuring.',
        'rejected' => 'Deze aanvraag is afgewezen.',
        'requested_by' => 'Gevraagd door :name',
        'asked' => 'Gevraagd :time',
        'answered' => 'Beantwoord :time',
        'overdue' => 'Te laat',
        'due' => 'Verwacht :time',
        'waiting_on' => 'Wacht op :names',
        'source_email' => 'per e-mail beantwoord',
    ],

    'portal' => [
        'title' => 'Goedkeuring',
        'pending' => 'Je aanvraag wacht op een goedkeuring.',
        'approved' => 'Je aanvraag is goedgekeurd.',
        'rejected' => 'Je aanvraag is afgewezen.',
    ],

    'token' => [
        'title' => 'Deze aanvraag goedkeuren',
        'intro' => 'Er is je gevraagd het volgende goed te keuren.',
        'expired_title' => 'Deze link werkt niet meer',
        'expired' => 'Hij is misschien al gebruikt, ingetrokken, of gewoon verlopen. Log in om te zien wat er nog op je wacht.',
        'done_approved' => 'Dank je — vastgelegd als goedgekeurd.',
        'done_rejected' => 'Dank je — vastgelegd als afgewezen.',
        'sign_in' => 'Inloggen bij Ticktz',
    ],

    'flash' => [
        'requested' => 'Goedkeuring aangevraagd.',
        'approved' => 'Vastgelegd als goedgekeurd.',
        'rejected' => 'Vastgelegd als afgewezen.',
        'cancelled' => 'Goedkeuring ingetrokken.',
    ],

    'errors' => [
        'already_decided' => 'Die goedkeuring is al beantwoord.',
        'unknown_outcome' => 'Een goedkeuring wordt gegeven of geweigerd.',
        'no_approvers' => 'Die goedkeuringsworkflow kwam op niemand uit, dus er is niets aangevraagd.',
        'nobody_named' => 'Kies een goedkeuringsworkflow of noem minstens één fiatteur.',
    ],

    'log' => [
        'requested' => 'Goedkeuring aangevraagd: :subject',
        'approved' => ':name keurde :subject goed',
        'rejected' => ':name wees :subject af',
        'settled_approved' => 'Goedgekeurd: :subject',
        'settled_rejected' => 'Afgewezen: :subject',
        'cancelled' => 'Goedkeuring ingetrokken: :subject',
        'no_approvers' => 'Goedkeuring kwam op niemand uit: :subject',
        'step_skipped' => 'Goedkeuringsstap ":step" had geen fiatteurs en is overgeslagen',
    ],

    'admin' => [
        'title' => 'Goedkeuringsworkflows',
        'subtitle' => 'Wie moet ja zeggen, en in welke volgorde.',
        'add' => 'Nieuwe workflow',
        'edit' => 'Workflow bewerken',
        'empty' => 'Nog geen goedkeuringsworkflows.',
        'empty_hint' => 'Een workflow is een geordende lijst stappen. Eén stap met één persoon is een enkele goedkeuring; meerdere stappen lopen na elkaar.',
        'created' => 'Workflow :name aangemaakt.',
        'updated' => 'Workflow :name bijgewerkt.',
        'deleted' => 'Workflow verwijderd. Lopende goedkeuringen blijven ongemoeid.',
        'confirm_delete' => 'Deze workflow verwijderen? Aanvraagtypes die hem gebruiken vragen dan geen goedkeuring meer; lopende goedkeuringen gaan gewoon door.',
        'add_step' => 'Stap toevoegen',
        'remove_step' => 'Stap verwijderen',
        'used_by' => ':count aanvraagtype|:count aanvraagtypes',
        'instructions' => 'Wat je de fiatteur meegeeft',
        'instructions_help' => 'Wordt getoond in de melding en op de beslispagina.',
        'steps_help' => 'Stappen lopen op volgorde. Binnen een stap worden de fiatteurs tegelijk gevraagd.',
    ],
];
