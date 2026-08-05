<?php

/*
 * The screen where a workspace uploads emoji of its own.
 *
 * Its own file, like the roles screen: a screen with a subject of its own, and
 * the text around it is about something else.
 */

return [
    'title' => 'Emoji',
    'description' => 'The pictures :workspace named itself',

    // No colon-word in this sentence: Laravel reads :naam as a placeholder, and
    // an explanation about shortcodes that quietly replaces itself is the one
    // joke nobody sees coming.
    'explanation' => 'Upload a picture, give it a name, and everybody here can type it between colons — in a message and as a reaction.',

    'name' => 'Name',
    'name_placeholder' => 'for example: shipit',
    'name_hint' => 'Lower case letters, digits, - and _.',
    'image' => 'Picture',
    'image_hint' => 'png, jpg, gif or webp, 512 kB at most. A gif keeps moving.',
    'upload' => 'Add',
    'uploading' => 'Working…',

    'preview' => 'How it looks',
    'added_by' => 'Added by :name',
    'added_by_unknown' => 'Added',
    'delete' => 'Remove',
    'delete_question' => 'Remove :name?',
    'delete_explanation' => 'Messages that use it stay as they are; they read as the name somebody typed again.',
    'cancel' => 'Cancel',

    'empty' => 'No emoji of your own yet. The first one is usually a logo.',
    'count' => '{1}1 emoji|[2,*]:count emoji',
    'too_many' => 'More than :count emoji in one workspace is more than a picker keeps readable.',
];
