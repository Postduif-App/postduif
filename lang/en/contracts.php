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

    'field-types' => [
        'text' => 'Text',
        'multiline' => 'Text over several lines',
        'date' => 'Date',
        'checkbox' => 'Tickbox',
        'signature' => 'Signature',
        'initials' => 'Initials',
    ],

];
