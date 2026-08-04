<?php

/*
 * The panels a workspace is run from: the webhooks that let something outside
 * post inside, the files put behind a download link, and the links that let
 * somebody in.
 *
 * Grouped by the panel a reader is looking at rather than by the component that
 * draws it — a webhooks section is the same section whether the channel
 * settings dialog or a settings page hangs it there, and keying by component
 * would make every refactor a rename.
 */

return [

    'webhooks' => [
        'heading' => 'Webhooks',
        'explanation' => 'Laat iets buiten Pcom in dit kanaal posten, onder een eigen naam en herkenbaar als bot.',
        'add' => 'Toevoegen',
        'name_label' => 'Naam',
        'name_hint' => 'Waar deze webhook voor is. Alleen jullie zien dit.',
        'bot_name_label' => 'Post als',
        'bot_name_hint' => 'De naam bij de berichten. Er staat altijd BOT naast.',
        'body_path_label' => 'Waar staat de tekst',

        /*
         * Split around the two code samples it points at. The samples are the
         * explanation — a path is easier to recognise than to describe — so the
         * sentence is cut where they stand rather than sewn back together with
         * a placeholder that would have to carry markup.
         */
        'body_path_hint_lead' => 'Leeg laten als de afzender dit stuurt:',
        'body_path_hint_middle' => 'Stuurt hij iets anders, wijs dan met punten aan waar de tekst staat —',
        'body_path_hint_or' => ', of',
        'body_path_hint_tail' => 'voor het eerste item uit een lijst.',

        'cancel' => 'Annuleren',
        'create' => 'Aanmaken',
        'create_failed' => 'Aanmaken is niet gelukt. Controleer de namen.',
        'path_failed' => 'Dat pad kon niet opgeslagen worden.',
        'none' => 'Nog geen webhooks in dit kanaal.',
        'posts_as' => 'Post als :name',
        'revoked' => 'ingetrokken',
        'last_used' => 'laatst gebruikt :at',
        'never_used' => 'nog niet gebruikt',
        'revoke' => 'Intrekken',
        'path_label' => 'Tekst uit',
        'url_gone' => 'De URL van deze webhook is niet meer op te vragen.',
        'new_url' => 'Nieuwe URL',
        'hide_url' => 'Verbergen',
        'show_url' => 'Toon URL',
        'copied' => 'Gekopieerd',
        'copy' => 'Kopiëren',
        'replace' => 'Vervangen',
        'replace_hint' => 'De huidige URL stopt dan met werken',
        'footer_lead' => 'Stuur een POST naar de URL met een JSON-body zoals deze:',
        'footer_tail' => 'Staat er een pad bij “tekst uit”, dan mag de afzender sturen wat hij al stuurt en halen wij de tekst daar vandaan.',
    ],

    'transfers' => [
        'heading' => 'Bestanden versturen',
        'description_everyone' => 'Alles wat vanuit :workspace klaarstaat achter een downloadlink',
        'description_own' => 'Wat jij vanuit :workspace hebt klaargezet',
        'files_label' => 'Bestanden',
        'files_hint' => 'Samen maximaal :size. Elk bestandstype mag — het gaat er aan de andere kant altijd als download uit, nooit als pagina.',
        'title_label' => 'Onderwerp (optioneel)',
        'title_placeholder' => 'Offerte week 32',
        'message_label' => 'Bericht (optioneel)',
        'message_placeholder' => 'Laat maar weten wat je ervan vindt.',
        'audience_legend' => 'Wie deze link mag gebruiken',
        'recipients_label' => 'E-mailadressen',
        'recipients_hint' => 'Eén per regel. Iedereen krijgt een eigen link gemaild; de link hierboven werkt dan niet meer op zichzelf.',
        'validity_label' => 'Link blijft geldig',
        'validity_days' => '{1}:count dag|[2,*]:count dagen',
        'password_label' => 'Wachtwoord (optioneel)',
        'password_placeholder' => 'Geen',
        'max_downloads_label' => 'Maximaal aantal downloads',
        'max_downloads_placeholder' => 'Onbeperkt',
        'password_warning' => 'Stuur een wachtwoord altijd los van de link — via een appje of aan de telefoon. Zichtbaar getypt, want dit is geen accountwachtwoord maar iets wat je moet kunnen voorlezen.',
        'uploading' => 'Bezig met uploaden — :percentage%',
        'submit' => 'Klaarzetten',
        'empty_title' => 'Er staat niets klaar',
        'empty_hint' => 'Handig voor het bestand dat niet in een bericht past. De ontvanger heeft geen account nodig — de link is genoeg.',
        'untitled' => 'Zonder onderwerp',
        'file_count' => '{1}1 bestand|[2,*]:count bestanden',
        'downloads_open' => ':count keer opgehaald',
        'downloads_capped' => ':count van :max opgehaald',
        'sent_by' => 'van :name',
        'valid_until' => 'tot :date',
        'dead_expired' => 'verlopen',
        'dead_revoked' => 'ingetrokken',
        'dead_exhausted' => 'opgebruikt',
        'cleared' => ':state · bestanden weg op :date',
        'copy_link' => 'Downloadlink kopiëren',
        'copied' => 'Gekopieerd',
        'copy' => 'Kopiëren',
        'revoke' => 'Verzending intrekken',
        'log_summary' => 'Laatste downloads (:count)',
        'log_unknown' => 'onbekend',
        'log_whole_archive' => 'alles',
        'log_single_file' => '1 bestand',
        'recipient_revoked' => 'ingetrokken',
        'recipient_downloads' => ':count keer opgehaald',
        'revoke_recipient' => 'Link voor :email intrekken',
        'cancel' => 'Annuleren',
        'revoke_title' => 'Deze verzending intrekken?',
        'revoke_description' => 'De link stopt meteen met werken. Wie hem al heeft, krijgt te zien dat de verzending is ingetrokken — wat al gedownload is, blijft natuurlijk waar het is.',
        'revoke_confirm' => 'Intrekken',
    ],

    'invites' => [
        'role_legend' => 'Wie er via deze link binnenkomt',
        'role_member' => 'Lid',
        'role_member_hint' => 'Hoort erbij. Vindt de openbare kanalen zelf en ziet wie er in de workspace zitten.',
        'role_guest' => 'Gast',
        'role_guest_hint' => 'Iemand van buiten. Ziet alleen de kanalen die je aanvinkt.',
        'channels_legend' => 'Kanalen voor deze gast',
        'no_channels' => 'Er zijn nog geen kanalen om iemand voor uit te nodigen.',
        'max_uses_label' => 'Maximaal aantal keer te gebruiken',
        'max_uses_placeholder' => 'Onbeperkt',
        'validity_label' => 'Geldig gedurende',
        'validity_one_day' => '1 dag',
        'validity_seven_days' => '7 dagen',
        'validity_thirty_days' => '30 dagen',
        'validity_unlimited' => 'Onbeperkt',
        'submit' => 'Link aanmaken',
        'empty_title' => 'Er zijn nog geen uitnodigingslinks',
        'empty_hint' => 'Handig als je niet weet wie er precies binnenkomt — een groep tegelijk, of een adres dat je niet hebt.',
        'created_by' => 'gemaakt door :name',
        'uses_open' => ':count keer gebruikt',
        'uses_capped' => ':count van :max gebruikt',
        'dead_expired' => 'verlopen',
        'dead_revoked' => 'ingetrokken',
        'dead_exhausted' => 'opgebruikt',
        'unlimited_validity' => 'onbeperkt geldig',
        'valid_until' => 'geldig tot :date',
        'copy_link' => 'Link kopiëren',
        'copied' => 'Gekopieerd',
        'copy' => 'Kopiëren',
        'revoke' => 'Uitnodigingslink intrekken',
        'revoke_short' => 'Intrekken',
        'cancel' => 'Annuleren',
        'revoke_title' => 'Deze uitnodigingslink intrekken?',
        'revoke_description' => 'De link werkt daarna niet meer, ook niet voor wie hem al heeft. Wie er eerder mee binnenkwam, blijft gewoon lid.',
        'revoke_confirm' => 'Intrekken',
    ],

];
