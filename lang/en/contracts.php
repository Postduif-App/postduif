<?php

/*
 * Everything a person reads around a contract.
 *
 * The upload refusals come first and are deliberately spelled out rather than
 * kept short: each of these sentences is the only thing somebody is told at the
 * moment their file will not go through, and "something went wrong" leaves a
 * person at a closed door with no key.
 */

return [

    'upload' => [
        'empty' => 'This file is empty or has no pages.',
        'not-a-pdf' => 'Only PDF files can be signed. Save the document as a PDF first.',
        'unreadable' => 'This PDF could not be processed. Protected or damaged files will not go through; save the document again without a password and try once more.',
        'executable' => 'This PDF contains script or an embedded file. That cannot be signed — save the document again as a plain PDF, without form logic or attachments.',
        'too-large' => 'This file is larger than :max MB. Save the PDF smaller — choosing "reduced" or "standard" instead of "press quality" is usually enough.',
        'too-many-pages' => 'This document has more than :max pages. Split it up, or send only the part that needs signing.',
    ],

    'editor' => [
        'back' => 'Back',
        'save' => 'Save',
        'zoom_in' => 'Zoom in',
        'zoom_out' => 'Zoom out',
        'tool' => 'What you are placing',
        'tool_hint' => 'Pick a kind of field and click the page where it should go. Dragging moves it, the corners resize it.',
        'selected' => 'Selected field',
        'field_label' => 'Label',
        'required' => 'Must be filled in',
        'for_signer' => 'To be filled in by',
        'remove_field' => 'Remove field',
        'page_count' => '{1}1 page|[2,*]:count pages',
        'field_count' => '{0}no fields yet|{1}1 field|[2,*]:count fields',
        'frozen' => 'This contract can no longer be changed. Somebody has signed it, or it was withdrawn — moving a field would change what they agreed to.',
        'failed' => 'The document could not be loaded.',
        'reload' => 'Reload the page',
    ],

    'send' => [
        'duplicate_address' => 'The same email address appears twice. Everybody who signs needs a link of their own, so an address can only stand for one signer.',
    ],

    'sign' => [
        'addressed_to' => 'This request is in the name of :name.',
        'autosaves' => 'What you fill in is kept as you go. You can close this screen and carry on later.',
        'saved' => 'Saved.',
        'signature_pending' => 'Signature',
        'closed' => [
            'signed' => [
                'title' => 'You have already signed this',
                'body' => 'There is nothing further for you to do. Whoever asked has been told. Keep the confirmation mail — your own copy is in it.',
            ],
            'completed' => [
                'title' => 'This contract is complete',
                'body' => 'Everybody who was asked has signed. There is nothing further for you to do.',
            ],
            'declined' => [
                'title' => 'You turned this request down',
                'body' => 'You indicated that you did not want to sign. If that is not what you meant, contact whoever sent it — they can send a new request.',
            ],
            'expired' => [
                'title' => 'This request has expired',
                'body' => 'The deadline has passed and it can no longer be signed. Ask whoever sent it for a new request; the document itself still exists.',
            ],
            'cancelled' => [
                'title' => 'This request was withdrawn',
                'body' => 'Whoever sent this has stopped it. Often that means a changed version is on its way. If you know nothing about it, do get in touch.',
            ],
        ],
    ],

    'signature' => [
        'title_signature' => 'Add your signature',
        'title_initials' => 'Add your initials',
        'hint_signature' => 'Draw with your mouse or finger, or type your name. You only have to do this once: it goes into every signature field on this contract.',
        'hint_initials' => 'Initials are the same, only smaller. You set these once too, and they go on every page that asks for them.',
        'draw' => 'Draw',
        'type' => 'Type',
        'clear' => 'Clear',
        'use' => 'Use this one',
        'your_name' => 'Your name',
        'legal' => 'Both ways count as a simple electronic signature. Which of the two you chose is recorded with the contract.',
    ],

    'field-types' => [
        'text' => 'Text',
        'multiline' => 'Text over several lines',
        'date' => 'Date',
        'checkbox' => 'Tickbox',
        'signature' => 'Signature',
        'initials' => 'Initials',
    ],

];
