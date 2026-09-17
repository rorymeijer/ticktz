<?php

declare(strict_types=1);

return [
    'title' => 'Kennisbank',
    'portal_title' => 'Helpcentrum',
    'subtitle' => 'Wat de servicedesk weet, één keer opgeschreven.',
    'portal_subtitle' => 'Antwoorden op wat het vaakst gevraagd wordt. Zoek eerst, meld daarna.',

    'search' => [
        'placeholder' => 'Zoek een antwoord…',
        'results' => ':count artikel gevonden|:count artikelen gevonden',
        'empty' => 'Daar is niets op gevonden.',
        'empty_hint' => 'Probeer een ander woord, of meld het en we helpen je verder.',
        'all' => 'Alle artikelen',
        'clear' => 'Zoekopdracht wissen',
    ],

    'categories' => [
        'title' => 'Categorieën',
        'all' => 'Alles',
        'add' => 'Categorie toevoegen',
        'edit' => 'Categorie bewerken',
        'empty' => 'Nog geen categorieën.',
        'uncategorised' => 'Zonder categorie',
        'article_count' => ':count artikel|:count artikelen',
        'created' => 'Categorie :name toegevoegd.',
        'updated' => 'Categorie :name bijgewerkt.',
        'deleted' => 'Categorie verwijderd. De artikelen staan nu zonder categorie.',
        'confirm_delete' => 'Deze categorie verwijderen? De artikelen blijven, zonder categorie.',
        'parent' => 'Valt onder',
        'parent_none' => 'Bovenste niveau',
        'name_translations' => 'Naam in andere talen',
        'parent_help' => 'Maar één niveau diep.',
    ],

    'articles' => [
        'title' => 'Artikelen',
        'add' => 'Artikel schrijven',
        'edit' => 'Artikel bewerken',
        'empty' => 'Nog geen artikelen.',
        'empty_hint' => 'Het eerste is meestal de vraag die je het vaakst beantwoordt.',
        'created' => 'Artikel :title aangemaakt.',
        'updated' => 'Artikel opgeslagen.',
        'deleted' => 'Artikel verwijderd.',
        'published' => 'Artikel gepubliceerd.',
        'unpublished' => 'Artikel terug naar concept.',
        'confirm_delete' => 'Dit artikel verwijderen? Tickets waar het aan hangt houden de koppeling.',
        'read_more' => 'Lees het artikel',
        'updated_at' => 'Bijgewerkt :time',
        'views' => ':count keer gelezen|:count keer gelezen',
        'views_label' => 'Gelezen',
        'ticket_count' => ':count ticket|:count tickets',
        'linked_tickets' => 'Beantwoordde deze tickets',
        'publish' => 'Publiceren',
        'unpublish' => 'Terug naar concept',
        'related' => 'Meer in deze categorie',
        'by' => 'Geschreven door :name',
    ],

    'fields' => [
        'title' => 'Titel',
        'slug' => 'URL',
        'slug_help' => 'Laat leeg om er een van de titel te maken.',
        'category' => 'Categorie',
        'excerpt' => 'Samenvatting',
        'excerpt_help' => 'Te zien in zoekresultaten en suggesties. Laat leeg om het begin van het artikel te gebruiken.',
        'body' => 'Artikel',
        'body_help' => 'Koppen, lijsten, links, tabellen en code. De rest wordt bij het opslaan verwijderd.',
        'status' => 'Status',
        'visibility' => 'Wie het mag lezen',
        'locale' => 'Taal',
        'locale_any' => 'Elke taal',
        'locale_help' => 'Een lezer ziet artikelen in de eigen taal plus de artikelen die voor iedereen zijn.',
        'note' => 'Wat er veranderd is',
        'note_help' => 'Optioneel, en de moeite waard: “VPN-poort gecorrigeerd” zegt over een jaar meer dan “versie 7”.',
        'position' => 'Volgorde',
    ],

    'status' => [
        'draft' => 'Concept',
        'published' => 'Gepubliceerd',
        'archived' => 'Gearchiveerd',
    ],

    'visibility' => [
        'public' => 'Iedereen',
        'internal' => 'Alleen agents',
        'public_help' => 'Zichtbaar op het portaal.',
        'internal_help' => 'Nooit te zien op het portaal, wat de categorie ook zegt.',
    ],

    'versions' => [
        'title' => 'Geschiedenis',
        'description' => 'Elke wijziging van de tekst, en wie hem maakte.',
        'empty' => 'Nog geen wijzigingen.',
        'version' => 'Versie :number',
        'current' => 'Huidig',
        'restore' => 'Deze terugzetten',
        'restored' => 'Versie :version teruggezet. De tekst die er stond staat nu ook in de geschiedenis.',
        'confirm_restore' => 'Versie :version terugzetten? De huidige tekst blijft in de geschiedenis staan.',
        'replaced_by_restore' => 'Vervangen door het terugzetten van versie :version',
        'by' => 'door :name',
    ],

    'suggestions' => [
        'title' => 'Dit is misschien het antwoord',
        'portal_title' => 'Voordat je dit meldt',
        'portal_hint' => 'Misschien staat het antwoord er al bij.',
        'none' => 'Nog niets gevonden.',
        'searching' => 'Bezig met zoeken…',
    ],

    'ticket' => [
        'title' => 'Kennisbank',
        'description' => 'De artikelen waarmee dit ticket is beantwoord.',
        'add' => 'Artikel koppelen',
        'search' => 'Zoek in de kennisbank…',
        'empty' => 'Geen artikelen gekoppeld.',
        'link' => 'Koppelen',
        'unlink' => 'Ontkoppelen',
    ],

    'editor' => [
        'write' => 'Schrijven',
        'preview' => 'Voorbeeld',
    ],

    'linked' => ':title aan dit ticket gekoppeld.',
    'unlinked' => 'Artikel ontkoppeld.',

    'filters' => [
        'status' => 'Status',
        'visibility' => 'Zichtbaarheid',
        'category' => 'Categorie',
        'any_status' => 'Elke status',
        'any_visibility' => 'Iedereen',
        'any_category' => 'Elke categorie',
    ],
];
