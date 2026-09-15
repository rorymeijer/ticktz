<?php

declare(strict_types=1);

return [
    'title' => 'Assets',
    'subtitle' => 'What the desk owns, and who has it.',
    'portal_title' => 'My equipment',
    'portal_subtitle' => 'The equipment assigned to you. Mention the tag when you file a request and we will know exactly which machine you mean.',
    'empty' => 'No assets yet.',
    'empty_hint' => 'Add one, or import the register you already keep in a spreadsheet.',
    'portal_empty' => 'Nothing is assigned to you.',

    'search' => [
        'placeholder' => 'Tag, serial number, name…',
        'results' => ':count asset|:count assets',
    ],

    'status' => [
        'in_stock' => 'In stock',
        'in_use' => 'In use',
        'in_repair' => 'Being repaired',
        'retired' => 'Retired',
        'disposed' => 'Disposed of',
    ],

    'relation' => [
        'connected_to' => 'Connected to',
        'installed_on' => 'Installed on',
        'hosts' => 'Hosts',
        'part_of' => 'Part of',
        'contains' => 'Contains',
        'depends_on' => 'Depends on',
        'required_by' => 'Required by',
        'backs_up' => 'Backs up',
        'backed_up_by' => 'Backed up by',
    ],

    'fields' => [
        'asset_tag' => 'Asset tag',
        'asset_tag_help' => 'Left blank, one is made from the type’s prefix.',
        'name' => 'Name',
        'type' => 'Type',
        'serial_number' => 'Serial number',
        'manufacturer' => 'Manufacturer',
        'model' => 'Model',
        'status' => 'Status',
        'location' => 'Location',
        'assigned_to' => 'Assigned to',
        'organization' => 'Organisation',
        'team' => 'Team',
        'purchased_at' => 'Purchased',
        'warranty_ends_at' => 'Warranty ends',
        'purchase_cost' => 'Purchase cost',
        'currency' => 'Currency',
        'notes' => 'Notes',
        'unassigned' => 'Nobody',
    ],

    'actions' => [
        'add' => 'Add an asset',
        'edit' => 'Edit asset',
        'relate' => 'Link another asset',
        'unrelate' => 'Unlink',
        'import' => 'Import from CSV',
        'link' => 'Link',
        'unlink' => 'Unlink',
    ],

    'sections' => [
        'details' => 'Details',
        'attributes' => 'Attributes',
        'relations' => 'Related assets',
        'relations_empty' => 'Nothing connected yet.',
        'tickets' => 'Tickets about this',
        'tickets_empty' => 'No tickets yet.',
        'lifecycle' => 'Purchase and warranty',
    ],

    'filters' => [
        'any_type' => 'Any type',
        'any_status' => 'Any status',
        'any_organization' => 'Any organisation',
        'warranty_expired' => 'Warranty expired',
    ],

    'warranty' => [
        'expired' => 'Warranty expired',
        'ends' => 'Warranty until :date',
    ],

    'ticket' => [
        'title' => 'Assets',
        'description' => 'The equipment this ticket is about.',
        'empty' => 'No assets linked.',
        'add' => 'Link an asset',
        'search' => 'Search the register…',
        'suggested' => 'Assigned to the requester',
    ],

    'flash' => [
        'created' => 'Asset :tag added.',
        'updated' => 'Asset saved.',
        'deleted' => 'Asset removed. Tickets it was linked to keep the link.',
        'related' => 'Assets linked.',
        'unrelated' => 'Link removed.',
        'linked' => ':tag linked to this ticket.',
        'unlinked' => 'Asset unlinked.',
    ],

    'confirm' => [
        'delete' => 'Remove this asset? Tickets it was linked to keep the link.',
        'unrelate' => 'Remove the link between these two assets?',
    ],

    'errors' => [
        'self_relation' => 'An asset cannot be linked to itself.',
        'unknown_relation' => 'That is not a kind of link assets can have.',
    ],

    'admin' => [
        'title' => 'Asset types',
        'subtitle' => 'The kinds of thing the desk keeps track of, and the extra attributes each one carries.',
        'add' => 'New type',
        'edit' => 'Edit type',
        'empty' => 'No asset types yet.',
        'empty_hint' => 'A laptop, a server, a phone, a licence — whatever the desk is asked about.',
        'created' => 'Type :name added.',
        'updated' => 'Type :name updated.',
        'deleted' => 'Type removed.',
        'in_use' => 'That type still has assets. Move or remove them first.',
        'confirm_delete' => 'Remove this type?',
        'fields' => 'Extra attributes',
        'fields_help' => 'Chosen from the asset fields defined in Custom fields, so a field is defined once and used everywhere.',
        'fields_empty' => 'No asset fields have been defined yet.',
        'tag_prefix' => 'Tag prefix',
        'tag_prefix_help' => 'Tags for this type are numbered from it: LAP-0001, LAP-0002.',
        'asset_count' => ':count asset|:count assets',
    ],

    'import' => [
        'title' => 'Import assets',
        'subtitle' => 'Bring in the register you already keep in a spreadsheet. Nothing is written until you confirm.',
        'file' => 'CSV file',
        'file_help' => 'Comma or semicolon separated, with a header row. Up to :max rows.',
        'default_type' => 'Type for rows that do not name one',
        'default_type_none' => 'None — every row must name its type',
        'columns' => 'Columns the importer understands',
        'columns_help' => 'Everything else in the file is ignored. Only asset_tag and name are needed; a row without a tag gets one.',
        'preview' => 'Check the file',
        'confirm' => 'Import :count row|Import :count rows',
        'will_create' => ':count new',
        'will_update' => ':count updated',
        'has_errors' => ':count row has a problem|:count rows have a problem',
        'errors_note' => 'These rows are skipped. The rest still import.',
        'line' => 'Line :line',
        'action' => ['create' => 'New', 'update' => 'Update'],
        'done' => 'Imported: :created created, :updated updated.',
        'nothing' => 'Nothing to import.',
        'matching' => 'Rows are matched on asset_tag, so importing the same file twice updates rather than duplicates.',

        'errors' => [
            'empty' => 'That file has no rows in it.',
            'expired' => 'That import is no longer pending. Upload the file again.',
            'no_name' => 'This row has no name.',
            'no_type' => 'This row does not name a type, and no default was chosen.',
            'unknown_type' => 'There is no asset type called ":type".',
            'unknown_status' => '":status" is not a status an asset can have.',
            'unknown_user' => 'There is no account for ":user".',
            'unknown_organization' => 'There is no organisation called ":organization".',
            'bad_date' => 'Could not read ":value" as a date in :column.',
        ],
    ],
];
