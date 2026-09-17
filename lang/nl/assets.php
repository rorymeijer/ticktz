<?php

declare(strict_types=1);

return [
    'title' => 'Assets',
    'subtitle' => 'Wat de servicedesk beheert, en wie het heeft.',
    'portal_title' => 'Mijn apparatuur',
    'portal_subtitle' => 'De apparatuur die aan jou is toegewezen. Noem het assetnummer bij een melding, dan weten we precies welk apparaat je bedoelt.',
    'empty' => 'Nog geen assets.',
    'empty_hint' => 'Voeg er een toe, of importeer het overzicht dat je al in een spreadsheet bijhoudt.',
    'portal_empty' => 'Er is niets aan jou toegewezen.',

    'search' => [
        'placeholder' => 'Assetnummer, serienummer, naam…',
        'results' => ':count asset|:count assets',
    ],

    'status' => [
        'in_stock' => 'Op voorraad',
        'in_use' => 'In gebruik',
        'in_repair' => 'In reparatie',
        'retired' => 'Uitgefaseerd',
        'disposed' => 'Afgevoerd',
    ],

    'relation' => [
        'connected_to' => 'Verbonden met',
        'installed_on' => 'Geïnstalleerd op',
        'hosts' => 'Draait',
        'part_of' => 'Onderdeel van',
        'contains' => 'Bevat',
        'depends_on' => 'Afhankelijk van',
        'required_by' => 'Nodig voor',
        'backs_up' => 'Maakt back-up van',
        'backed_up_by' => 'Back-up via',
    ],

    'fields' => [
        'asset_tag' => 'Assetnummer',
        'asset_tag_help' => 'Laat leeg om er een te laten maken op basis van het prefix van het type.',
        'name' => 'Naam',
        'type' => 'Type',
        'serial_number' => 'Serienummer',
        'manufacturer' => 'Fabrikant',
        'model' => 'Model',
        'status' => 'Status',
        'location' => 'Locatie',
        'assigned_to' => 'Toegewezen aan',
        'organization' => 'Organisatie',
        'team' => 'Team',
        'purchased_at' => 'Aangeschaft',
        'warranty_ends_at' => 'Garantie tot',
        'purchase_cost' => 'Aanschafprijs',
        'currency' => 'Valuta',
        'notes' => 'Notities',
        'unassigned' => 'Niemand',
    ],

    'actions' => [
        'add' => 'Asset toevoegen',
        'edit' => 'Asset bewerken',
        'relate' => 'Andere asset koppelen',
        'unrelate' => 'Ontkoppelen',
        'import' => 'Importeren uit CSV',
        'link' => 'Koppelen',
        'unlink' => 'Ontkoppelen',
    ],

    'sections' => [
        'details' => 'Gegevens',
        'attributes' => 'Kenmerken',
        'relations' => 'Gekoppelde assets',
        'relations_empty' => 'Nog niets gekoppeld.',
        'tickets' => 'Tickets hierover',
        'tickets_empty' => 'Nog geen tickets.',
        'lifecycle' => 'Aanschaf en garantie',
    ],

    'filters' => [
        'any_type' => 'Elk type',
        'any_status' => 'Elke status',
        'any_organization' => 'Elke organisatie',
        'warranty_expired' => 'Garantie verlopen',
    ],

    'warranty' => [
        'expired' => 'Garantie verlopen',
        'ends' => 'Garantie tot :date',
    ],

    'ticket' => [
        'title' => 'Assets',
        'description' => 'De apparatuur waar dit ticket over gaat.',
        'empty' => 'Geen assets gekoppeld.',
        'add' => 'Asset koppelen',
        'search' => 'Zoek in het assetregister…',
        'suggested' => 'Toegewezen aan de melder',
    ],

    'flash' => [
        'created' => 'Asset :tag toegevoegd.',
        'updated' => 'Asset opgeslagen.',
        'deleted' => 'Asset verwijderd. Tickets houden hun koppeling.',
        'related' => 'Assets gekoppeld.',
        'unrelated' => 'Koppeling verwijderd.',
        'linked' => ':tag aan dit ticket gekoppeld.',
        'unlinked' => 'Asset ontkoppeld.',
    ],

    'confirm' => [
        'delete' => 'Deze asset verwijderen? Tickets houden hun koppeling.',
        'unrelate' => 'De koppeling tussen deze twee assets verwijderen?',
    ],

    'errors' => [
        'self_relation' => 'Een asset kan niet aan zichzelf gekoppeld worden.',
        'unknown_relation' => 'Dat is geen koppeling die assets kunnen hebben.',
    ],

    'admin' => [
        'title' => 'Assettypes',
        'subtitle' => 'De soorten dingen die de servicedesk bijhoudt, en de extra kenmerken die elk type draagt.',
        'add' => 'Nieuw type',
        'edit' => 'Type bewerken',
        'empty' => 'Nog geen assettypes.',
        'empty_hint' => 'Een laptop, een server, een telefoon, een licentie — waar de servicedesk ook over gebeld wordt.',
        'created' => 'Type :name toegevoegd.',
        'updated' => 'Type :name bijgewerkt.',
        'deleted' => 'Type verwijderd.',
        'in_use' => 'Dat type heeft nog assets. Verplaats of verwijder die eerst.',
        'confirm_delete' => 'Dit type verwijderen?',
        'fields' => 'Extra kenmerken',
        'fields_help' => 'Gekozen uit de assetvelden bij Aangepaste velden, zodat een veld één keer wordt gedefinieerd en overal wordt gebruikt.',
        'fields_empty' => 'Er zijn nog geen assetvelden gedefinieerd.',
        'tag_prefix' => 'Prefix assetnummer',
        'tag_prefix_help' => 'Nummers voor dit type lopen hierop door: LAP-0001, LAP-0002.',
        'asset_count' => ':count asset|:count assets',
    ],

    'import' => [
        'title' => 'Assets importeren',
        'subtitle' => 'Haal het overzicht binnen dat je al in een spreadsheet bijhoudt. Er wordt niets weggeschreven tot je bevestigt.',
        'file' => 'CSV-bestand',
        'file_help' => 'Gescheiden door komma of puntkomma, met een kopregel. Maximaal :max regels.',
        'default_type' => 'Type voor regels die er geen noemen',
        'default_type_none' => 'Geen — elke regel moet zijn type noemen',
        'columns' => 'Kolommen die de import begrijpt',
        'columns_help' => 'De rest van het bestand wordt genegeerd. Alleen asset_tag en name zijn nodig; een regel zonder nummer krijgt er een.',
        'preview' => 'Bestand controleren',
        'confirm' => ':count regel importeren|:count regels importeren',
        'will_create' => ':count nieuw',
        'will_update' => ':count bijgewerkt',
        'has_errors' => ':count regel heeft een probleem|:count regels hebben een probleem',
        'errors_note' => 'Deze regels worden overgeslagen. De rest wordt gewoon geïmporteerd.',
        'line' => 'Regel :line',
        'action' => ['create' => 'Nieuw', 'update' => 'Bijwerken'],
        'done' => 'Geïmporteerd: :created nieuw, :updated bijgewerkt.',
        'nothing' => 'Niets te importeren.',
        'matching' => 'Regels worden gematcht op asset_tag, dus hetzelfde bestand twee keer importeren werkt bij in plaats van te verdubbelen.',

        'errors' => [
            'empty' => 'Dat bestand bevat geen regels.',
            'expired' => 'Die import staat niet meer klaar. Upload het bestand opnieuw.',
            'no_name' => 'Deze regel heeft geen naam.',
            'no_type' => 'Deze regel noemt geen type, en er is geen standaard gekozen.',
            'unknown_type' => 'Er is geen assettype met de naam ":type".',
            'unknown_status' => '":status" is geen status die een asset kan hebben.',
            'unknown_user' => 'Er is geen account voor ":user".',
            'unknown_organization' => 'Er is geen organisatie met de naam ":organization".',
            'bad_date' => 'Kon ":value" in :column niet als datum lezen.',
        ],
    ],
];
