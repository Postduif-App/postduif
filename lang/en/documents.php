<?php

/*
 * Everything on screen around a document: the list, the document itself, the
 * editor and what it says while it is working.
 *
 * Its own file, the way timeclock.php and workflows.php are. A document is not
 * one component but half an application — a list, a document view, an editor
 * with a menu and a toolbar — and spreading those across five surface files
 * only makes them harder to find.
 *
 * What already had a word elsewhere is not here: the tab is
 * conversation.view.documents, the channel setting lives in channels.php, and the
 * policy's choices are an enum in enums.php.
 */

return [
    'conflict' => [
        'message' => ':name has saved this document in the meantime. Reload the page to carry on from the newest version.',
        'somebody' => 'Somebody else',
    ],
    'list' => [
        'title' => 'Documents',
        'create' => 'New document',
        'updated' => 'Updated :when by :who',
        'somebody' => 'somebody',
        'empty' => 'No documents yet. A document is the place for whatever you would otherwise explain again every few weeks: agreements, a runbook, who does what.',
    ],
    'slash' => [
        'heading' => 'Blocks',
        'empty' => 'No block by that name.',
        'blocks' => [
            'paragraph' => 'Text',
            'heading_one' => 'Heading 1',
            'heading_two' => 'Heading 2',
            'heading_three' => 'Heading 3',
            'bulleted_list' => 'Bulleted list',
            'numbered_list' => 'Numbered list',
            'todo_list' => 'To-do list',
            'blockquote' => 'Quote',
            'callout' => 'Callout',
            'divider' => 'Divider',
        ],
    ],
    'toolbar' => [
        'label' => 'Formatting',
        'bold' => 'Bold',
        'italic' => 'Italic',
        'underline' => 'Underline',
        'strike' => 'Strikethrough',
        'code' => 'Code',
    ],
    'view' => [
        'back' => 'Back to the documents',
        'untitled' => 'Untitled',
        'title_label' => 'Title of this document',
        'moved' => 'Somebody else has updated this documents.',
        'dismiss' => 'Later',
        'delete' => 'Delete document',
        'confirm' => 'Delete this document? Everyone in the channel loses it.',
        'reload' => 'Reload',
        'saving' => 'Saving…',
        'saved' => 'Saved',
        'unsaved' => 'Not saved',
    ],
    'create' => [
        'title' => 'New document',
        'description' => 'Give it a name. You write the contents afterwards, in the editor.',
        'name' => 'Name',
        'placeholder' => 'Agreements with the customer',
        'cancel' => 'Cancel',
        'submit' => 'Start',
    ],
    'editor' => [
        'placeholder' => 'Type something, or press / for blocks',
        'failed' => 'The editor could not be loaded.',
        'reload' => 'Reload the page',
    ],
];
