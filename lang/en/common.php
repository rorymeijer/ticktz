<?php

declare(strict_types=1);

return [
    'actions' => [
        'dismiss' => 'Dismiss',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'confirm' => 'Confirm',
        'close' => 'Close',
        'search' => 'Search',
        'filter' => 'Filter',
        'reset' => 'Reset',
        'back' => 'Back',
        'next' => 'Next',
        'previous' => 'Previous',
        'submit' => 'Submit',
        'export' => 'Export',
        'import' => 'Import',
        'add' => 'Add',
        'remove' => 'Remove',
        'duplicate' => 'Duplicate',
        'view' => 'View',
        'refresh' => 'Refresh',
        'select' => 'Select',
        'clear' => 'Clear',
    ],
    'labels' => [
        'name' => 'Name',
        'description' => 'Description',
        'status' => 'Status',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'created_at' => 'Created',
        'updated_at' => 'Updated',
        'actions' => 'Actions',
        'yes' => 'Yes',
        'no' => 'No',
        'none' => 'None',
        'all' => 'All',
        'optional' => 'optional',
        'required' => 'required',
        'language' => 'Language',
        'key' => 'Key',
        'type' => 'Type',
        'order' => 'Order',
        'default' => 'Default',
        'unassigned' => 'Unassigned',
        'system' => 'System',
    ],
    'empty' => [
        'title' => 'Nothing here yet',
        'description' => 'There is no data to show for the current filters.',
    ],
    'pagination' => [
        'label' => 'Pagination',
        'showing' => 'Showing :from–:to of :total',
        'per_page' => 'Per page',
    ],
    'confirm' => [
        'title' => 'Are you sure?',
        'delete' => 'This action cannot be undone.',
    ],
    'time' => [
        'just_now' => 'just now',
        'minutes_ago' => ':count minute ago|:count minutes ago',
        'hours_ago' => ':count hour ago|:count hours ago',
        'days_ago' => ':count day ago|:count days ago',
        'overdue_by' => 'overdue by :duration',
        'remaining' => ':duration remaining',
    ],
    /*
    |--------------------------------------------------------------------------
    | Choosing a person
    |--------------------------------------------------------------------------
    |
    | The picker searches the server as you type rather than holding the whole
    | directory, so it has states a dropdown does not: nothing typed yet,
    | nothing found, and the search itself having failed. The third has to say
    | so — an empty list that means "the request was refused" reads as "there
    | is nobody", and somebody will believe it.
    |
    */
    'people' => [
        'search' => 'Search by name or e-mail',
        'searching' => 'Searching…',
        'no_matches' => 'Nobody matches :term.',
        'failed' => 'That search could not be run. Try again in a moment.',
        'nobody' => 'Nobody',
        'clear' => 'Clear the selection',
        'remove' => 'Remove :name',
        'result_count' => ':count found',
        'more_hint' => 'Keep typing to narrow this down.',
    ],

    'errors' => [
        'forbidden' => 'You do not have permission to perform this action.',
        'not_found' => 'The requested item could not be found.',
        'generic' => 'Something went wrong. Please try again.',
    ],
];
