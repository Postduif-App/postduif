<?php

/*
 * The screen where a workspace writes its own roles.
 *
 * A file of its own because it is a screen with its own subject: who may do
 * what, which is a different question from the settings around it.
 */

return [
    'title' => 'Roles',
    'description' => 'Who may do what in :workspace',

    'explanation' => 'A role is a name and a set of rights. You can write your own — a supplier, a volunteer, a board member — and tick exactly what it may do.',

    'system' => 'Built in',
    'external' => 'From outside',
    'external_hint' => 'Somebody from outside only sees the channels they were invited to. Public channels do not exist for them.',
    'external_locked' => 'This is fixed once somebody holds the role: it moves people across the line that decides which channels exist for them.',

    'holders' => '{0}Nobody holds this role|{1}1 person|[2,*]:count people',
    'name' => 'Name',
    'abilities' => 'What this role may do',
    'not_yours' => 'This role reaches further than your own, so you cannot change it.',
    'beyond_your_own' => 'You cannot give a role more than you hold yourself.',
    'still_held' => 'People still hold this role. Give them another one first.',
    'system_role' => 'A built-in role cannot be deleted.',
    'too_many' => 'More than :count roles in one workspace is more than anybody can hold in their head.',

    'new' => 'New role',
    'new_name' => 'What is the role called?',
    'create' => 'Create',
    'save' => 'Save',
    'delete' => 'Delete role',
];
