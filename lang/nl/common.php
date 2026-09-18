<?php

declare(strict_types=1);

return [
    'actions' => [
        'dismiss' => 'Sluiten',
        'save' => 'Opslaan',
        'cancel' => 'Annuleren',
        'create' => 'Aanmaken',
        'edit' => 'Bewerken',
        'delete' => 'Verwijderen',
        'confirm' => 'Bevestigen',
        'close' => 'Sluiten',
        'search' => 'Zoeken',
        'filter' => 'Filteren',
        'reset' => 'Herstellen',
        'back' => 'Terug',
        'next' => 'Volgende',
        'previous' => 'Vorige',
        'submit' => 'Versturen',
        'export' => 'Exporteren',
        'import' => 'Importeren',
        'add' => 'Toevoegen',
        'remove' => 'Verwijderen',
        'duplicate' => 'Dupliceren',
        'view' => 'Bekijken',
        'refresh' => 'Vernieuwen',
        'select' => 'Selecteren',
        'clear' => 'Wissen',
    ],
    'labels' => [
        'name' => 'Naam',
        'description' => 'Omschrijving',
        'status' => 'Status',
        'active' => 'Actief',
        'inactive' => 'Inactief',
        'enabled' => 'Ingeschakeld',
        'disabled' => 'Uitgeschakeld',
        'created_at' => 'Aangemaakt',
        'updated_at' => 'Bijgewerkt',
        'actions' => 'Acties',
        'yes' => 'Ja',
        'no' => 'Nee',
        'none' => 'Geen',
        'all' => 'Alle',
        'optional' => 'optioneel',
        'required' => 'verplicht',
        'language' => 'Taal',
        'key' => 'Sleutel',
        'type' => 'Type',
        'order' => 'Volgorde',
        'default' => 'Standaard',
        'unassigned' => 'Niet toegewezen',
        'system' => 'Systeem',
    ],
    'empty' => [
        'title' => 'Nog niets te zien',
        'description' => 'Er zijn geen gegevens voor de huidige filters.',
    ],
    'pagination' => [
        'label' => 'Paginering',
        'showing' => ':from–:to van :total getoond',
        'per_page' => 'Per pagina',
    ],
    'confirm' => [
        'title' => 'Weet je het zeker?',
        'delete' => 'Deze actie kan niet ongedaan worden gemaakt.',
    ],
    'time' => [
        'just_now' => 'zojuist',
        'minutes_ago' => ':count minuut geleden|:count minuten geleden',
        'hours_ago' => ':count uur geleden|:count uur geleden',
        'days_ago' => ':count dag geleden|:count dagen geleden',
        'overdue_by' => ':duration te laat',
        'remaining' => 'nog :duration',
    ],
    /*
    |--------------------------------------------------------------------------
    | Iemand kiezen
    |--------------------------------------------------------------------------
    |
    | De kiezer zoekt tijdens het typen op de server in plaats van de hele
    | lijst vast te houden, en heeft daardoor toestanden die een keuzelijst
    | niet heeft: er is nog niets getypt, er is niets gevonden, en het zoeken
    | zelf is mislukt. Dat laatste moet het zeggen — een lege lijst die "het
    | verzoek is geweigerd" betekent leest als "er is niemand", en iemand
    | gelooft dat.
    |
    */
    'people' => [
        'search' => 'Zoek op naam of e-mailadres',
        'searching' => 'Bezig met zoeken…',
        'no_matches' => 'Niemand komt overeen met :term.',
        'failed' => 'Die zoekopdracht kon niet worden uitgevoerd. Probeer het zo nog eens.',
        'nobody' => 'Niemand',
        'clear' => 'Keuze wissen',
        'remove' => ':name verwijderen',
        'result_count' => ':count gevonden',
        'more_hint' => 'Typ verder om de lijst te verkleinen.',
    ],

    /*
    |--------------------------------------------------------------------------
    | De handleiding
    |--------------------------------------------------------------------------
    */
    'manual' => [
        'title' => 'Handleiding',
        'subtitle' => 'Hoe Ticktz werkt, en hoe je het gebruikt.',
        'explain_this_page' => 'Leg deze pagina uit',
        'open_full' => 'Open de volledige handleiding',
        'loading' => 'Hoofdstuk ophalen…',
        'failed' => 'Dat hoofdstuk kon niet worden opgehaald. Probeer het zo nog eens.',
        'empty' => 'Er zijn nog geen hoofdstukken die je kunt lezen.',
        'back' => 'Alle hoofdstukken',
        'contents' => 'Hoofdstukken',
    ],

    'errors' => [
        'forbidden' => 'Je hebt geen rechten voor deze actie.',
        'not_found' => 'Het gevraagde item is niet gevonden.',
        'generic' => 'Er ging iets mis. Probeer het opnieuw.',
    ],
];
