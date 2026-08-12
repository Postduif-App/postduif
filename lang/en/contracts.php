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

    'field-types' => [
        'text' => 'Text',
        'multiline' => 'Text over several lines',
        'date' => 'Date',
        'checkbox' => 'Tickbox',
        'signature' => 'Signature',
        'initials' => 'Initials',
    ],

];
