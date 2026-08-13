<?php

/*
 * Alles wat een mens leest rond een contract.
 *
 * De weigeringen bij het uploaden staan bovenaan en zijn met opzet uitgeschreven
 * in plaats van kort gehouden: elk van deze zinnen is het enige wat iemand te
 * horen krijgt op het moment dat zijn bestand niet doorkomt, en "er ging iets
 * mis" laat een mens met een dichte deur en geen sleutel achter.
 */

return [

    'upload' => [
        'empty' => 'Dit bestand is leeg of bevat geen pagina\'s.',
        'not-a-pdf' => 'Alleen PDF-bestanden kunnen ondertekend worden. Sla het document eerst op als PDF.',
        'unreadable' => 'Deze PDF kon niet verwerkt worden. Beveiligde of beschadigde bestanden komen er niet doorheen; sla het document opnieuw op zonder wachtwoord en probeer het dan nog eens.',
        'no_processor' => 'Deze server kan nog geen PDF\'s voor ondertekening verwerken. Er ontbreekt software die daarvoor nodig is — geef dit door aan wie de applicatie beheert; aan jouw bestand ligt het niet.',
        'executable' => 'In deze PDF zit script of een ingesloten bestand. Dat kan niet ondertekend worden — sla het document opnieuw op als een gewone PDF, zonder formulierlogica of bijlagen.',
        'too-large' => 'Dit bestand is groter dan :max MB. Sla de PDF kleiner op — meestal scheelt "verkleind" of "standaard" in plaats van "drukwerk" al genoeg.',
        'too-many-pages' => 'Dit document heeft meer dan :max pagina\'s. Splits het op of stuur alleen het deel dat getekend moet worden.',
    ],

    'editor' => [
        'back' => 'Terug',
        'save' => 'Opslaan',
        'zoom_in' => 'Inzoomen',
        'zoom_out' => 'Uitzoomen',
        'tool' => 'Wat zet je neer',
        'tool_hint' => 'Kies een soort vak en klik op de pagina waar het moet komen. Slepen verplaatst, de hoekpunten maken groter of kleiner.',
        'selected' => 'Geselecteerd vak',
        'field_label' => 'Label',
        'required' => 'Verplicht in te vullen',
        'for_signer' => 'In te vullen door',
        'signers' => 'Ondertekenaars',
        'no_signers' => 'Er is nog niemand benoemd, dus elk vak gaat naar de eerste ondertekenaar.',
        'name_signers' => 'Ondertekenaars benoemen',
        'remove_field' => 'Vak verwijderen',
        'page_count' => '{1}1 pagina|[2,*]:count pagina\'s',
        'field_count' => '{0}nog geen vakken|{1}1 vak|[2,*]:count vakken',
        'frozen' => 'Dit contract is niet meer aan te passen. Er is al getekend, of het is ingetrokken — een vak verplaatsen zou veranderen waar iemand mee akkoord ging.',
        'failed' => 'Het document kon niet geladen worden.',
        'reload' => 'Pagina opnieuw laden',
    ],

    'send' => [
        'title' => 'Naar wie gaat dit?',
        'name' => 'Naam',
        'email' => 'E-mailadres',
        'add' => 'Nog iemand',
        'remove' => 'Verwijderen',
        'pick_member' => 'Een collega kiezen…',
        'valid_days' => 'Tekenen kan nog (dagen)',
        'notify_channel' => 'Meldingen in kanaal',
        'no_channel' => 'Geen kanaal — alleen per mail',
        'submit' => 'Versturen',
        'save_signers' => 'Ondertekenaars opslaan',
        'save_hint' => 'Sla eerst op als je per vak wilt kiezen wie het invult. Daarna staan de namen in de editor.',
        'sign_myself' => 'Ik onderteken zelf ook',
        'sign_myself_hint' => 'Je komt als eerste ondertekenaar op de lijst en krijgt een eigen link per mail. Vakken die jij invult, zet je in de editor op jouw naam.',
        'you' => '(jij)',
        'duplicate_address' => 'Hetzelfde e-mailadres staat er twee keer bij. Ieder die tekent heeft een eigen link nodig, dus per adres kan er maar één ondertekenaar zijn.',
    ],

    'sign' => [
        'addressed_to' => 'Dit verzoek staat op naam van :name.',
        'autosaves' => 'Wat je invult wordt vanzelf bewaard. Je kunt dit scherm tussendoor sluiten en later verder gaan.',
        'saved' => 'Opgeslagen.',
        'errors' => [
            'not_outstanding' => 'Dit contract loopt niet meer, dus er valt niets in te trekken.',
            'closed' => 'Dit contract kan niet meer getekend worden. Het is ingetrokken, verlopen, of je hebt er al op gereageerd.',
            'already' => 'Er is al op dit verzoek gereageerd. Ververs de pagina om te zien wat er staat.',
            'incomplete' => '{1}Er is nog één vak dat ingevuld moet worden: :fields.|[2,*]Er zijn nog :count vakken die ingevuld moeten worden: :fields.',
            'no_document' => 'Het document bij dit contract is niet te vinden. Neem contact op met degene die het stuurde — teken niets tot dat is uitgezocht.',
            'document_changed' => 'Het document is veranderd sinds het naar je verstuurd is. Om die reden kan er nu niet getekend worden. Neem contact op met degene die het stuurde.',
        ],
        'sign' => 'Ondertekenen',
        'decline' => 'Afwijzen',
        'decline_title' => 'Dit verzoek afwijzen',
        'decline_hint' => 'Je geeft aan dat je dit contract niet tekent. Dat is definitief: dezelfde link werkt daarna niet meer.',
        'decline_reason' => 'Waarom niet? (mag je overslaan)',
        'decline_confirm' => 'Afwijzen',
        'cancel' => 'Terug',
        'remaining' => '{0}Alles is ingevuld.|{1}Nog 1 vak te gaan.|[2,*]Nog :count vakken te gaan.',
        'signature_pending' => 'Handtekening',
        'closed' => [
            'signed' => [
                'title' => 'Je hebt dit al getekend',
                'body' => 'Er is verder niets meer voor je te doen. De aanvrager heeft bericht gekregen. Bewaar de bevestigingsmail: daar staat je eigen kopie in.',
            ],
            'completed' => [
                'title' => 'Dit contract is rond',
                'body' => 'Iedereen die gevraagd was heeft getekend. Er is verder niets meer voor je te doen.',
            ],
            'declined' => [
                'title' => 'Je hebt dit verzoek afgewezen',
                'body' => 'Je hebt aangegeven niet te willen tekenen. Is dat niet wat je bedoelde, neem dan contact op met degene die het stuurde — die kan een nieuw verzoek sturen.',
            ],
            'expired' => [
                'title' => 'Dit verzoek is verlopen',
                'body' => 'De einddatum is voorbij en er kan niet meer getekend worden. Vraag degene die het stuurde om een nieuw verzoek; het document zelf is er nog.',
            ],
            'cancelled' => [
                'title' => 'Dit verzoek is ingetrokken',
                'body' => 'Degene die dit stuurde heeft het stopgezet. Vaak betekent dat er een gewijzigde versie aankomt. Weet je van niets, neem dan even contact op.',
            ],
        ],
    ],

    'signature' => [
        'title_signature' => 'Zet je handtekening',
        'title_initials' => 'Zet je paraaf',
        'hint_signature' => 'Teken met je muis of je vinger, of typ je naam. Je hoeft dit maar één keer te doen: hij komt in elk handtekeningvak van dit contract te staan.',
        'hint_initials' => 'Een paraaf is hetzelfde, maar kleiner. Ook deze zet je één keer, en hij komt op elke pagina te staan waar om een paraaf gevraagd wordt.',
        'draw' => 'Tekenen',
        'type' => 'Typen',
        'clear' => 'Wissen',
        'use' => 'Deze gebruiken',
        'your_name' => 'Je naam',
        'legal' => 'Allebei de manieren tellen als een eenvoudige elektronische handtekening. Welke van de twee je koos wordt vastgelegd bij het contract.',
    ],

    'audit' => [
        'heading' => 'Audittrail',
        'intro' => 'Deze pagina hoort bij het contract ":title" en is automatisch toegevoegd door :workspace. Ze legt vast wie er gevraagd is te tekenen, wanneer zij dat deden en onder welk document.',
        'document' => 'Document',
        'sent_by' => 'Verstuurd door',
        'completed_at' => 'Afgerond op',
        'hash' => 'SHA-256 van het document zoals het verstuurd is:',
        'opened_at' => 'Geopend op',
        'signed_at' => 'Getekend op',
        'declined_at' => 'Afgewezen op',
        'ip' => 'IP-adres',
        'method' => 'Handtekening',
        'typed_as' => 'Ingetypt als',
        'signed_hash' => 'Getekend onder',
        'hash_matches' => 'hetzelfde document als hierboven',
        'reason' => 'Reden',
        'outcome' => 'Uitkomst',
        'no_answer' => 'Niet gereageerd',
        'never' => 'Nooit',
        'filename_suffix' => '(ondertekend)',
    ],

    'chat' => [
        'signed' => ':name heeft ":title" getekend.',
        'declined' => ':name heeft ":title" niet getekend.',
        'completed' => '{1}":title" is getekend.|[2,*]Iedereen heeft ":title" getekend.',
    ],

    'detail' => [
        'cancel' => 'Intrekken',
        'retry' => 'Opnieuw proberen',
        'copy_link' => 'Link kopiëren',
        'post_channel' => 'Kanaal',
        'post' => 'In kanaal plaatsen',
        'tally' => ':done van de :total getekend',
        'edit' => 'Vakken bewerken',
        'remind' => 'Herinnering sturen',
        'sent_by' => 'Verstuurd door',
        'pages' => 'Omvang',
        'expires_at' => 'Tekenen kan tot',
        'no_deadline' => 'Geen einddatum',
        'completed_at' => 'Afgerond op',
        'sign_yourself' => 'Jij moet zelf nog tekenen',
        'people' => 'Ondertekenaars',
        'nobody' => 'Er zijn nog geen ondertekenaars uitgenodigd.',
        'signed' => 'Getekend',
        'declined' => 'Afgewezen',
        'opened' => 'Geopend',
        'waiting' => 'Nog niets',
        'reminded' => 'herinnerd :date',
        'document' => 'Document bekijken',
        'signed_copy' => 'Ondertekende versie',
        'copy_pending' => 'De ondertekende versie wordt samengesteld.',
        'copy_failed' => 'De ondertekende versie kon niet samengesteld worden. De handtekeningen staan vast.',
    ],

    'list' => [
        'title' => 'Contracten',
        'new' => 'Nieuw contract',
        'new_hint' => 'Upload de PDF die getekend moet worden. Wie hem tekent en wanneer, bepaal je op het volgende scherm — met het document erbij.',
        'field_title' => 'Waar gaat het over',
        'field_file' => 'De PDF',
        'upload' => 'Uploaden',
        'empty' => 'Nog geen contracten',
        'empty_hint' => 'Upload een PDF, zet er invulvakken op en stuur hem naar wie moet tekenen.',
    ],

    'errors' => [
        'not_outstanding' => 'Dit contract loopt niet meer, dus er valt niets in te trekken.',
    ],

    'field-types' => [
        'text' => 'Tekst',
        'multiline' => 'Tekst over meer regels',
        'date' => 'Datum',
        'checkbox' => 'Vinkje',
        'signature' => 'Handtekening',
        'initials' => 'Paraaf',
    ],

];
