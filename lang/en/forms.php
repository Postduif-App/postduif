<?php

/*
 * Everything that ends up on screen around forms: the builder, the filling-in
 * pages, the card in a channel, and the message the bot sends afterwards.
 *
 * That last block — 'dm' — goes to whoever made the form and so follows their
 * language rather than the answerer's. See SendFormAnswers, which switches the
 * locale before it builds the sentences.
 */

return [
    'types' => [
        'short-text' => 'Short text',
        'long-text' => 'Long text',
        'choice' => 'One choice',
        'multiple-choice' => 'Several choices',
        'number' => 'Number',
        'date' => 'Date',
        'boolean' => 'Yes or no',
    ],

    'answers' => [
        'empty' => '—',
        'yes' => 'Yes',
        'no' => 'No',
        'anonymous' => 'Somebody from outside',
        'via_link' => 'Through the shared link',
    ],

    'dm' => [
        'intro' => ':name filled in ":form".',
        'anonymous_intro' => 'A filled-in ":form" came in through the shared link.',
        'line' => '**:question**: :answer',
        'answers' => 'All the answers are with the form itself as well.',
    ],

    /*
     * The card in a channel. What is missing here matters as much as what is
     * not: no submission count and no "you already filled this in". The card is
     * drawn from one payload broadcast to the whole channel — see
     * PresentMessage::formCard — so it cannot know either without telling
     * everybody.
     */
    'card' => [
        'fill' => 'Fill in',
        'closed' => 'This form is closed',
        'expired' => 'The deadline has passed',
        'empty' => 'This form has no questions yet',
        'questions' => ':count question|:count questions',
    ],

    'screen' => [
        'title' => 'Forms',
        'description' => 'Questionnaires you put in a channel or share as a link. The answers come back to you.',
        'new' => 'New form',
        'none' => 'No form has been made here yet.',
        'open' => 'Open',
        'closed' => 'Closed',
        'shared' => 'Shared',
        'submissions' => 'Submissions',
        'edit' => 'Edit',
        'answers' => 'Answers',
        'delete' => 'Delete',
        'delete_confirm' => 'This form and everything filled into it will go. Continue?',
        'form_title' => 'Name of the form',
        'form_description' => 'Explanation up front',
        'form_description_hint' => 'What somebody reads before they start. May stay empty.',
        'closes_at' => 'Closes on',
        'closes_at_hint' => 'Leaving it empty means: until you stop it yourself.',
        'allows_multiple' => 'May be filled in more than once',
        'notify_channel' => 'Channel for anonymous submissions',
        'notify_channel_hint' => 'Somebody filling in through the link has no conversation with you. Pick where those answers land — leaving it empty means: only on the answers screen.',
        'fields' => 'Questions',
        'field_label' => 'The question',
        'field_hint' => 'Explanation',
        'field_type' => 'Kind of answer',
        'field_required' => 'Required',
        'field_options' => 'Choices',
        'field_options_hint' => 'One per line.',
        'field_key' => 'Reference for workflows',
        'add_field' => 'Add a question',
        'remove_field' => 'Remove',
        'move_up' => 'Up',
        'move_down' => 'Down',
        'no_fields' => 'No questions yet. Add one, or there is nothing to fill in.',
        'save' => 'Save',
        'close_form' => 'Close',
        'reopen_form' => 'Open again',
        'share' => 'Make a shareable link',
        'reshare' => 'Make a new link',
        'unshare' => 'Withdraw the link',
        'share_hint' => 'With this link anybody who has it can fill in this form, account or not. Making a new link switches the old one off.',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'post' => 'Put it in a channel',
        'post_channel' => 'Which channel',
    ],

    'fill' => [
        'title' => 'Fill in a form',
        'send' => 'Send',
        'sent' => 'Sent. Thank you.',
        'closed' => 'This form takes nothing more.',
        'already' => 'You have already filled this form in.',
        'empty' => 'There are no questions in this form yet.',
        'author' => 'From :name',
        'expired' => 'The deadline for this form has passed.',
        'closes_on' => 'Closes on :date',
        'back' => 'Back to the chat',
        'anonymous_notice' => 'You are filling this in through a shared link. Your name is not sent along.',
        'named_notice' => ':name sees who filled this in.',
    ],

    'answers_screen' => [
        'title' => 'Answers to :form',
        'none' => 'Nothing has been filled in yet.',
        'when' => 'When',
        'who' => 'Who',
        'export' => 'Download as CSV',
        'back' => 'Back to forms',
        'count' => ':count submission|:count submissions',
    ],

    'errors' => [
        'too_many' => 'No more than :count fit in one workspace.',
        'closed' => 'This form is closed.',
        'already_submitted' => 'You have already filled this form in.',
        'no_fields' => 'A form without questions cannot be filled in.',
        'unknown_link' => 'This link no longer works.',
        'options_required' => 'A choice question needs at least two choices.',
        'options_unexpected' => 'This kind of question does not take choices.',
    ],
];
