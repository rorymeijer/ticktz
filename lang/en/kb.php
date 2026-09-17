<?php

declare(strict_types=1);

return [
    'title' => 'Knowledge base',
    'portal_title' => 'Help centre',
    'subtitle' => 'What the desk knows, written down once.',
    'portal_subtitle' => 'Answers to the things people ask most. Search before you file a request.',

    'search' => [
        'placeholder' => 'Search for an answer…',
        'results' => ':count article found|:count articles found',
        'empty' => 'Nothing matched that.',
        'empty_hint' => 'Try a different word, or file a request and we will help.',
        'all' => 'All articles',
        'clear' => 'Clear search',
    ],

    'categories' => [
        'title' => 'Categories',
        'all' => 'Everything',
        'add' => 'Add category',
        'edit' => 'Edit category',
        'empty' => 'No categories yet.',
        'uncategorised' => 'Uncategorised',
        'article_count' => ':count article|:count articles',
        'created' => 'Category :name added.',
        'updated' => 'Category :name updated.',
        'deleted' => 'Category removed. Its articles are now uncategorised.',
        'confirm_delete' => 'Remove this category? Its articles stay, without a category.',
        'parent' => 'Inside',
        'parent_none' => 'Top level',
        'parent_help' => 'One level of nesting only.',
        'name_translations' => 'Name in other languages',
    ],

    'articles' => [
        'title' => 'Articles',
        'add' => 'Write an article',
        'edit' => 'Edit article',
        'empty' => 'No articles yet.',
        'empty_hint' => 'The first one is usually the question you answer most often.',
        'created' => 'Article :title created.',
        'updated' => 'Article saved.',
        'deleted' => 'Article removed.',
        'published' => 'Article published.',
        'unpublished' => 'Article moved back to draft.',
        'confirm_delete' => 'Remove this article? Tickets it was linked to keep the link.',
        'read_more' => 'Read the article',
        'updated_at' => 'Updated :time',
        'views' => ':count view|:count views',
        'views_label' => 'Views',
        'ticket_count' => ':count ticket|:count tickets',
        'linked_tickets' => 'Answered these tickets',
        'publish' => 'Publish',
        'unpublish' => 'Back to draft',
        'related' => 'More in this category',
        'by' => 'Written by :name',
    ],

    'fields' => [
        'title' => 'Title',
        'slug' => 'URL',
        'slug_help' => 'Left blank, one is made from the title.',
        'category' => 'Category',
        'excerpt' => 'Summary',
        'excerpt_help' => 'Shown in search results and suggestions. Left blank, the opening of the article is used.',
        'body' => 'Article',
        'body_help' => 'Headings, lists, links, tables and code. Anything else is removed when you save.',
        'status' => 'Status',
        'visibility' => 'Who can read it',
        'locale' => 'Language',
        'locale_any' => 'Any language',
        'locale_help' => 'A reader sees articles in their own language plus the ones marked for everybody.',
        'note' => 'What changed',
        'note_help' => 'Optional, and worth writing: “fixed the VPN port” beats “version 7” a year later.',
        'position' => 'Order',
    ],

    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'visibility' => [
        'public' => 'Everyone',
        'internal' => 'Agents only',
        'public_help' => 'Visible on the portal.',
        'internal_help' => 'Never shown on the portal, whatever the category says.',
    ],

    'versions' => [
        'title' => 'History',
        'description' => 'Every change to the text, and who made it.',
        'empty' => 'No changes yet.',
        'version' => 'Version :number',
        'current' => 'Current',
        'restore' => 'Put this back',
        'restored' => 'Restored version :version. The text that was there is now in the history too.',
        'confirm_restore' => 'Put version :version back? The current text is kept in the history.',
        'replaced_by_restore' => 'Replaced by a restore of version :version',
        'by' => 'by :name',
    ],

    'suggestions' => [
        'title' => 'This might be the answer',
        'portal_title' => 'Before you file this',
        'portal_hint' => 'One of these may already answer it.',
        'none' => 'Nothing matched yet.',
        'searching' => 'Looking…',
    ],

    'ticket' => [
        'title' => 'Knowledge base',
        'description' => 'The articles this ticket was answered with.',
        'add' => 'Link an article',
        'search' => 'Search the knowledge base…',
        'empty' => 'No articles linked.',
        'link' => 'Link',
        'unlink' => 'Unlink',
    ],

    'editor' => [
        'write' => 'Write',
        'preview' => 'Preview',
    ],

    'linked' => 'Linked :title to this ticket.',
    'unlinked' => 'Article unlinked.',

    'filters' => [
        'status' => 'Status',
        'visibility' => 'Visibility',
        'category' => 'Category',
        'any_status' => 'Any status',
        'any_visibility' => 'Anyone',
        'any_category' => 'Any category',
    ],
];
