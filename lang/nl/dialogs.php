<?php

/*
 * The windows that open on top of a conversation: sending a secret, asking for
 * one, sending files, putting a question to the channel, writing a notice.
 *
 * Grouped by the window a reader is looking at rather than by the component
 * that draws it — a dialog opened from the message field and the same dialog
 * opened by a slash command say the same things.
 */

return [
    'actions' => [
        'cancel' => 'Annuleren',
    ],

    'send_secret' => [
        'title' => 'Een geheim versturen',
        'description' => 'Wordt versleuteld in je browser. Onze server kan het niet lezen, en de ontvanger kan het één keer bekijken.',
        'description_no_channel' => 'Er wordt niets in een kanaal geplaatst — je krijgt alleen een link.',
        'recipient_label' => 'Voor wie',
        'recipient_optional' => '(optioneel)',
        'recipient_none' => 'Niemand in het bijzonder',
        'recipient_hint' => 'Alleen een label. Iedereen met de link kan het geheim openen.',
        'label_label' => 'Waar gaat het over',
        'label_placeholder' => 'Wachtwoord staging-database',
        'label_hint_own' => 'Alleen jij ziet dit terug, in je eigen lijst.',
        'label_hint_channel' => 'Dit komt in het kanaal te staan. Zet er dus niet het geheim zelf in.',
        'secret_label' => 'Het geheim',
        'password_label' => 'Wachtwoord (optioneel)',
        'password_placeholder' => 'Geen',
        'password_hint' => 'Geef het wachtwoord via een ander kanaal door dan de link zelf — anders beschermt het niets.',
        'expires_label' => 'Verloopt na',
        'expires_days' => '{1}:count dag|[2,*]:count dagen',
        'submit' => 'Klaarzetten',
        'error_form' => 'Er klopte iets niet aan het formulier.',
        'error_crypto' => 'Versleutelen lukte niet in deze browser. Zonder versleuteling wordt er niets verstuurd.',
        'link_title' => 'Kopieer deze link nu',
        'link_description' => 'Dit is de enige keer dat je hem ziet. De sleutel staat er achteraan en is nooit bij ons geweest — we kunnen hem dus niet opnieuw maken.',
        'link_warning' => 'Sluit je dit venster zonder te kopiëren, dan is het geheim onbereikbaar. Trek het dan in en zet een nieuwe klaar.',
        'link_copied' => 'Gekopieerd',
        'link_copy' => 'Kopieer de link',
        'link_done' => 'Klaar',
    ],

    'secret_request' => [
        'trigger' => 'Om een wachtwoord of sleutel vragen',
        'title' => 'Om gegevens vragen',
        'description' => 'De ander vult ze in op een eigen formulier. Alleen jij kunt ze daarna bekijken — de rest van het kanaal nooit, en de invuller ook niet meer.',
        'purpose_label' => 'Waarvoor',
        'purpose_placeholder' => 'Omgevingsvariabelen staging',
        'keys_label' => 'Welke sleutels',
        'keys_hint' => 'Eén per regel. Plak gerust regels uit een .env — alles achter de = wordt weggelaten, want die waarde hoort hier juist niet.',
        'days_label' => 'Verzoek blijft open (dagen)',
        'burn_label' => 'Verwijderen zodra ik het bekeken heb',
        'burn_hint' => 'Je krijgt het één keer te zien. Handig voor iets dat meteen een server in gaat, onhandig als je het volgende week weer nodig hebt.',
        'submit' => 'Verzoek plaatsen',
    ],

    'transfer' => [
        'trigger' => 'Grote bestanden versturen via een link',
        'title' => 'Bestanden versturen',
        'description' => 'Voor wat te groot is om mee te sturen met een bericht. De link komt in dit kanaal te staan en werkt voor iedereen in deze workspace.',
        'files_label' => 'Bestanden',
        'files_hint' => 'Samen maximaal :size.',
        'title_label' => 'Onderwerp (optioneel)',
        'title_placeholder' => 'Opnames van dinsdag',
        'days_label' => 'Link blijft geldig (dagen)',
        'days_hint' => '{1}Daarna verdwijnen de bestanden. Maximaal :count dag in deze workspace.|[2,*]Daarna verdwijnen de bestanden. Maximaal :count dagen in deze workspace.',
        'uploading' => 'Bezig met uploaden — :percentage%',
        'submit' => 'Versturen',
    ],

    'poll' => [
        'title' => 'Een vraag stellen',
        'description' => 'Iedereen in dit kanaal kan stemmen — en ziet wie wat stemt.',
        'question_label' => 'Vraag',
        'question_placeholder' => 'Wanneer doen we de retro?',
        'options_label' => 'Antwoorden',
        'options_placeholder' => "Dinsdag\nWoensdag",
        'options_hint' => 'Eén per regel, minstens twee.',
        'duration_label' => 'Open gedurende',
        'duration_until_closed' => 'Tot ik hem sluit',
        'duration_one_hour' => '1 uur',
        'duration_eight_hours' => '8 uur',
        'duration_one_day' => '1 dag',
        'duration_three_days' => '3 dagen',
        'duration_one_week' => '1 week',
        'allows_multiple' => 'Meerdere antwoorden mogen',
        'submit' => 'Vraag plaatsen',
    ],

    'board_post' => [
        'title' => 'Op het prikbord zetten',
        'description' => 'Iedereen in :workspace kan dit lezen en erop reageren. Gasten niet.',
        'title_label' => 'Titel',
        'title_placeholder' => 'Waar gaat het over?',
        'body_label' => 'Bericht',
        'submit' => 'Plaatsen',
    ],
];
