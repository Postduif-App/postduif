<?php

/*
 * The screen where a workspace manages buttons of its own.
 *
 * Its own file, like the emoji and the roles screens: a screen with a subject
 * of its own, and the text around it is about something else.
 */

return [
    'title' => 'Shortcuts',
    'description' => 'Buttons of its own in the :workspace menu',

    'explanation' => 'Put an address behind a button and pick who sees it. The buttons sit in the menu under the workspace name, and turn up in search as well.',

    'label' => 'Name',
    'label_placeholder' => 'for example: Timesheets',
    'url' => 'Address',
    'url_placeholder' => 'https://',
    'url_hint' => 'Has to start with http:// or https://. The button opens in a new tab.',
    'emoji' => 'Icon',
    'emoji_hint' => 'Optional. Without one the button gets an arrow.',

    'roles' => 'Visible to',
    // Why it says this: a button with no roles is a button nobody sees, and
    // that is easier to make by accident than it is to find again.
    'roles_hint' => 'Tick who gets the button in their menu. With nothing ticked, nobody sees it.',
    'roles_none' => 'Nobody — this button is in nobody\'s menu.',
    'roles_all' => 'Everybody',
    'roles_external' => 'from outside',

    'add' => 'Add button',
    'adding' => 'Working…',
    'save' => 'Save',
    'edit' => 'Edit',
    'cancel' => 'Cancel',
    'delete' => 'Remove',
    'delete_question' => 'Are you sure you want to remove :label?',
    'delete_explanation' => 'The button disappears from everybody\'s menu. The address behind it goes on working.',

    'move_up' => 'Move up',
    'move_down' => 'Move down',

    'empty' => 'No buttons yet. The first one is usually the system everybody sits in all day.',
    'too_many' => 'More than :count buttons is more than a menu keeps readable.',
];
