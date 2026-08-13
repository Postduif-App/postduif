<?php

return [
    'title' => 'Mail texts',
    'description' => 'What the mails to the signers of :workspace say',

    'intro' => 'These texts go to people outside your workspace. Leave a field empty and we use our own text — it is already there in grey.',

    'kind' => 'Which mail',
    'language' => 'Language',
    'language_hint' => 'The mail goes out in the language of whoever sends the contract. Fill in the ones you use; the rest stays our text.',

    'subject' => 'Subject',
    'heading' => 'Heading',
    'body' => 'Text',
    'button_label' => 'Button text',
    'button_label_hint' => 'The button itself always stays.',

    'placeholders' => 'What you can insert',
    'placeholders_hint' => 'Click to insert at the cursor. A line holding something that comes up empty — no deadline, say — disappears entirely.',

    'reset' => 'Back to our text',
    'reset_confirm' => 'Your own text for this mail and language will be cleared.',

    'preview' => 'Preview',
    'preview_title' => 'This is how the mail will look',
    'preview_hint' => 'With made-up details, and with the text as it stands in the form right now.',
    'preview_close' => 'Close',

    'saved' => 'The mail texts have been saved.',

    'placeholder_not_here' => 'This mail has nothing to put in {{:placeholder}}. Pick one from the list below it.',

    'language_name' => [
        'nl' => 'Dutch',
        'en' => 'English',
    ],

    'placeholder' => [
        'button' => 'button',
        'signer' => 'signer',
        'sender' => 'sender',
        'workspace' => 'workspace',
        'title' => 'title',
        'message' => 'message',
        'expires' => 'expires',
        'signed_at' => 'signed_at',
    ],

    'hint' => [
        'button' => 'Where the button goes. Leave it out and we put the button under your text.',
        'signer' => 'The name of whoever gets this mail.',
        'sender' => 'Who sent the contract, or your workspace if that person is gone.',
        'workspace' => 'The name of your workspace.',
        'title' => 'The title of the contract.',
        'message' => 'The note sent along with this one contract, if there is one.',
        'expires' => 'The date the link expires, if there is a deadline.',
        'signed_at' => 'The date this person signed.',
    ],

    'sample' => [
        'workspace' => 'Bakker & Partners',
        'signer' => 'Anna de Vries',
        'sender' => 'Joris Bakker',
        'title' => 'Partnership agreement 2027',
        'message' => 'As discussed, with the change to article 4 worked in.',
    ],
];
