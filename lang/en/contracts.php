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
        'no_processor' => 'This server cannot process PDFs for signing yet. A piece of software it needs is missing — pass this on to whoever runs the application; there is nothing wrong with your file.',
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
        'signers' => 'Signers',
        'no_signers' => 'Nobody has been named yet, so every field goes to the first signer.',
        'name_signers' => 'Name the signers',
        'remove_field' => 'Remove field',
        'page_count' => '{1}1 page|[2,*]:count pages',
        'field_count' => '{0}no fields yet|{1}1 field|[2,*]:count fields',
        'frozen' => 'This contract can no longer be changed. Somebody has signed it, or it was withdrawn — moving a field would change what they agreed to.',
        'failed' => 'The document could not be loaded.',
        'reload' => 'Reload the page',
    ],

    'send' => [
        'title' => 'Who is this going to?',
        'name' => 'Name',
        'email' => 'Email address',
        'add' => 'Add somebody',
        'remove' => 'Remove',
        'pick_member' => 'Pick a colleague…',
        'valid_days' => 'Can be signed for (days)',
        'notify_channel' => 'Notifications in channel',
        'no_channel' => 'No channel — mail only',
        'submit' => 'Send',
        'save_signers' => 'Save signers',
        'save_hint' => 'Save first if you want to choose who fills in each field. The names then show up in the editor.',
        'sign_myself' => 'I am signing this too',
        'sign_myself_hint' => 'You go on the list as the first signer and get a link of your own by mail. Fields you fill in are assigned to your name in the editor.',
        'you' => '(you)',
        'duplicate_address' => 'The same email address appears twice. Everybody who signs needs a link of their own, so an address can only stand for one signer.',
    ],

    'sign' => [
        'addressed_to' => 'This request is in the name of :name.',
        'autosaves' => 'What you fill in is kept as you go. You can close this screen and carry on later.',
        'autosave_short' => 'Kept as you go',
        'saved' => 'Saved.',
        'errors' => [
            'not_outstanding' => 'This contract is no longer running, so there is nothing to withdraw.',
            'closed' => 'This contract can no longer be signed. It was withdrawn, it expired, or you have already responded to it.',
            'already' => 'This request has already been responded to. Refresh the page to see where it stands.',
            'incomplete' => '{1}There is one field left to fill in: :fields.|[2,*]There are :count fields left to fill in: :fields.',
            'no_document' => 'The document for this contract cannot be found. Contact whoever sent it — do not sign anything until that has been sorted out.',
            'document_changed' => 'The document has changed since it was sent to you. For that reason it cannot be signed now. Contact whoever sent it.',
        ],
        'sign' => 'Sign',
        'decline' => 'Turn down',
        'decline_title' => 'Turn this request down',
        'decline_hint' => 'You are saying you will not sign this contract. That is final: the same link will not work afterwards.',
        'decline_reason' => 'Why not? (you may skip this)',
        'decline_confirm' => 'Turn down',
        'cancel' => 'Back',
        'remaining' => '{0}Everything is filled in.|{1}1 field to go.|[2,*]:count fields to go.',
        'signature_pending' => 'Signature',
        'filled_by_other' => 'This was already filled in by somebody who signed earlier.',
        'signed_before' => '{1}One of the :total signers has already signed; what they filled in is already on the document.|[2,*]:count of the :total signers have already signed; what they filled in is already on the document.',
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

    'audit' => [
        'heading' => 'Audit trail',
        'intro' => 'This page belongs to the contract ":title" and was added automatically by :workspace. It records who was asked to sign, when they did so, and under which document.',
        'document' => 'Document',
        'sent_by' => 'Sent by',
        'completed_at' => 'Completed on',
        'hash' => 'SHA-256 of the document as it was sent:',
        'opened_at' => 'Opened on',
        'signed_at' => 'Signed on',
        'declined_at' => 'Turned down on',
        'ip' => 'IP address',
        'method' => 'Signature',
        'typed_as' => 'Typed as',
        'signed_hash' => 'Signed under',
        'hash_matches' => 'the same document as above',
        'reason' => 'Reason',
        'outcome' => 'Outcome',
        'no_answer' => 'No response',
        'never' => 'Never',
        'filename_suffix' => '(signed)',
    ],

    'chat' => [
        'signed' => ':name has signed ":title".',
        'declined' => ':name did not sign ":title".',
        'completed' => '{1}":title" has been signed.|[2,*]Everybody has signed ":title".',
    ],

    'detail' => [
        'cancel' => 'Withdraw',
        'delete' => 'Delete',
        'delete_confirm' => 'This contract, its PDF and everything on it are thrown away. Anybody holding a link ends up on an empty page. Withdrawing leaves it standing and explains that it was stopped.',
        'delete_confirm_signed' => 'This contract has been signed and completed. The signed PDF, the page recording who signed when, and the signatures themselves are thrown away. It cannot be undone, the signers are not told, and their own copy is then the only one left.',
        'retry' => 'Try again',
        'copy_link' => 'Copy link',
        'post_channel' => 'Channel',
        'post' => 'Post in a channel',
        'tally' => ':done of :total signed',
        'edit' => 'Edit fields',
        'remind' => 'Send a reminder',
        'sent_by' => 'Sent by',
        'pages' => 'Size',
        'expires_at' => 'Can be signed until',
        'no_deadline' => 'No deadline',
        'completed_at' => 'Completed on',
        'sign_yourself' => 'You still have to sign this yourself',
        'people' => 'Signers',
        'nobody' => 'No signers have been invited yet.',
        'signed' => 'Signed',
        'declined' => 'Turned down',
        'opened' => 'Opened',
        'waiting' => 'Nothing yet',
        'reminded' => 'reminded :date',
        'copy_sent' => 'document sent :date',
        'document' => 'View the document',
        'view_signed' => 'View the signed copy',
        'signed_copy' => 'Download the signed copy',
        'copy_pending' => 'The signed copy is being composed.',
        'copy_failed' => 'The signed copy could not be composed. The signatures are safe.',
        'send_copy' => 'Mail it to the signers',
        'send_copy_hint' => 'Everybody who signed gets the signed document as an attachment. That happens by itself once the contract is complete; this sends it again.',
        'duplicate' => 'Use again',
        'duplicate_title' => 'Use this contract again',
        'duplicate_explainer' => 'You get a new draft with the same PDF and the same fields. This contract and the signatures on it stay exactly as they are. Who signs the new one is chosen on the next screen.',
        'duplicate_name' => 'Name of the new contract',
        'duplicate_name_hint' => 'This name is fixed once the draft exists, so say where or who this copy is for.',
        'duplicate_default' => ':title (copy)',
        'duplicate_confirm' => 'Create the draft',
    ],

    /*
     * A template is a contract that is never sent. So it has no status but a
     * question — can it be used yet? — and everything below is about that
     * question and about what is still missing, spelled out rather than kept
     * short: "not ready" without saying why leaves somebody in front of a screen
     * full of buttons that do nothing.
     */
    'template' => [
        'title' => 'Template',
        'lead' => 'This document is never sent. It is kept to make contracts out of — through the API, or with one click later on — and what you set here holds for every contract that comes out of it.',
        'ready' => 'Ready to use',
        'not_ready' => 'Not ready to use yet',
        'missing' => 'What still has to happen:',
        'blockers' => [
            'document' => 'This template still needs a PDF.',
            'recipients' => 'Nobody has said how many people this goes to.',
            'fields' => 'There are no fields on the document yet.',
            'signature' => 'You said you would sign along, but you have not signed yet.',
        ],
        'recipients' => 'Number of recipients',
        'recipients_hint' => 'How many people will this be sent to? Their names are filled in when it is sent; here you only decide how many parties the fields are laid out for.',
        'recipients_save' => 'Save the number',
        'recipients_floor' => 'It cannot go below :count: there are fields on the document meant for those parties.',
        'sign_along' => 'I am signing this too',
        'sign_along_hint' => 'You sign once, on this template. Every contract made from it then carries your signature already, without you having to sign again.',
        'sign_now' => 'Sign the template',
        'signed' => 'You have signed this template.',
        'signed_locks' => 'While your signature is on it the fields cannot be moved. Clear the tickbox to edit the template again — your signature is then erased.',
        'parties' => '{1}1 party|[2,*]:count parties',
        'myself' => 'Myself',
        'recipient' => 'Recipient :number',
        'editor_hint' => 'This is a template: the recipients do not exist yet. You choose per field which party fills it in.',
    ],

    'list' => [
        'title' => 'Contracts',
        'tab_contracts' => 'Contracts',
        'tab_templates' => 'Templates',
        'as_template' => 'Keep as a template',
        'as_template_hint' => 'For a document you send over and over. It never goes out itself: you set how many people will sign it, sign along yourself if you want to, and then make contracts out of it.',
        'templates_empty' => 'No templates yet',
        'templates_empty_hint' => 'Upload a PDF with "keep as a template" ticked. That is worth doing for a document that goes out again every month.',
        'new' => 'New contract',
        'new_hint' => 'Upload the PDF that needs signing. Who signs it and by when are decided on the next screen, with the document in front of you.',
        'field_title' => 'What it is about',
        'field_file' => 'The PDF',
        'drop' => 'Drag the PDF here',
        'drop_or' => 'or click to choose one',
        'drop_hint' => 'PDF only, at most :size and :pages.',
        'drop_pages' => '{1}1 page|[2,*]:count pages',
        'replace' => 'Choose another',
        'remove' => 'Remove',
        'upload' => 'Upload',
        'empty' => 'No contracts yet',
        'empty_hint' => 'Upload a PDF, put fields on it and send it to whoever has to sign.',
        'search' => 'Search by title or signer',
        'clear_search' => 'Clear search',
        'no_results' => 'Nothing found',
        'no_results_hint' => 'No contract with this title, and nobody with this name or email address on the list of signers.',
    ],

    'errors' => [
        'not_outstanding' => 'This contract is no longer running, so there is nothing to withdraw.',
    ],

    /*
     * What the API says back to a system rather than to a reader.
     *
     * Written with the same care as the rest all the same: the person seeing
     * these is a developer working out why their call will not go through, and
     * "invalid request" is no help to them.
     */
    'api' => [
        'token_without_workspace' => 'This token is not tied to one workspace. Make a token for the workspace you want to send contracts from.',
        'no_workspace' => 'This workspace does not exist, does not do contracts, or you may not send them here.',
        'no_template' => 'There is no such template in this workspace.',
        'template_unfinished' => 'This template is not ready to send: it is missing a document, fields, the number of recipients, or the sender\'s signature.',
        'no_contract' => 'There is no such contract, or you may not see it.',
        'no_signed_copy' => 'There is no signed document to fetch yet (:state).',
        'wrong_recipient_count' => 'This template expects :expected recipient(s), you gave :given.',
        'duplicate_recipient' => 'Two recipients with the same email address: everybody signs under their own.',
        'recipient_is_sender' => ':email has already signed this template and is therefore already on it.',
        'unknown_field' => 'Field :field does not belong to this recipient.',
        'secret_without_url' => 'A callback_secret without a callback_url signs nothing.',
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
