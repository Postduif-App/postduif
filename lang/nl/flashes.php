<?php

/*
 * Wat de app terugzegt na een handeling: de regel boven een formulier of de
 * toast rechtsonder.
 *
 * Gegroepeerd naar waar het over gaat en niet naar welke controller het
 * verstuurt, want dezelfde zin komt soms uit twee plekken — een bericht
 * inplannen gebeurt zowel met een toast als met een gewone terugmelding.
 *
 * Zinnen met een naam erin staan hier als één regel met :name en niet als
 * losse stukjes. Aan elkaar geplakte tekst is in het Nederlands nog te volgen,
 * maar in een taal met een andere woordvolgorde niet meer te maken.
 */
return [
    'channel' => [
        'saved' => 'Kanaalinstellingen opgeslagen.',
        'deleted' => '#:name is verwijderd.',
        'unmuted' => 'Meldingen voor dit kanaal staan weer aan.',
        'muted' => 'Meldingen voor dit kanaal staan uit.',
        'muted_until' => 'Meldingen voor dit kanaal staan uit tot :time.',
        'forwarded' => 'Doorgestuurd naar #:name.',
        'archived' => '#:name is gearchiveerd.',
        'reopened' => '#:name is weer open.',

        /*
         * Alle drie de gevallen als hele zin, ook de nul. "Niemand toegevoegd"
         * is niet dezelfde zin met een ander getal erin: er is niets gebeurd,
         * en dat hoort te lezen als iets anders dan een telling.
         */
        'members_added' => '{0}Niemand toegevoegd.|{1}1 lid toegevoegd.|[2,*]:count leden toegevoegd.',
        'member_removed' => ':name is uit het kanaal verwijderd.',
    ],

    /*
     * Het delen van een kanaal met een andere workspace. Bewust in de
     * gebiedende wijs van wat er echt gebeurd is: "aangeboden" en niet
     * "gedeeld", want na deze zin kan de andere kant nog steeds nee zeggen.
     */
    'channel-share' => [
        'offered' => ':workspace is uitgenodigd voor dit kanaal.',
        'accepted' => 'Het kanaal staat nu ook in deze workspace.',
        'declined' => 'Uitnodiging afgewezen.',
        'revoked' => 'Het kanaal is niet langer gedeeld.',
        'members_added' => '{0}Niemand toegevoegd.|{1}1 lid toegevoegd.|[2,*]:count leden toegevoegd.',
    ],

    /*
     * Herinneringen. De tijd staat in de zin omdat dat de enige is die iemand
     * daarna nog kan controleren: het menu zegt "over een uur", en pas deze
     * zin zegt hoe laat dat is.
     */
    'reminder' => [
        'set' => 'We porren je :time.',
        'cancelled' => 'Herinnering ingetrokken.',
    ],

    /*
     * Geplande huddles. "Ingepland" en niet "aangemaakt": er gebeurt nu nog
     * niets, en de zin moet niet suggereren dat het kanaal al iets gemerkt
     * heeft.
     */
    'huddle' => [
        'scheduled' => 'Huddle ingepland voor :time.',
        'cancelled' => 'Huddle afgezegd.',
    ],

    'message' => [
        'scheduled' => 'Bericht ingepland.',
        'updated' => 'Bericht aangepast.',
        'withdrawn' => 'Bericht ingetrokken.',
    ],

    'form' => [
        'saved' => 'Formulier opgeslagen.',
        'deleted' => 'Formulier verwijderd.',
        'closed' => 'Formulier gesloten.',
        'reopened' => 'Formulier weer opengezet.',
        'shared' => 'Er is een nieuwe deelbare link. De vorige werkt niet meer.',
        'unshared' => 'De link is ingetrokken.',
        'posted' => 'Formulier in het kanaal gezet.',
    ],

    'poll' => [
        'closed' => 'Poll gesloten.',
        'reopened' => 'Poll heropend.',
    ],

    'contract' => [
        'created' => 'Document klaargezet. Zet nu de invulvakken op de pagina\'s.',
        'fields_saved' => 'Vakken opgeslagen.',
        'cancelled' => 'Contract ingetrokken. De links werken niet meer.',
        'deleted' => 'Contract verwijderd.',
        'posted' => 'Contract in het kanaal geplaatst.',
        'retrying' => 'We proberen de ondertekende versie opnieuw samen te stellen.',
        'signers_saved' => '{1}Ondertekenaar opgeslagen. Kies in de editor per vak wie het invult.|[2,*]:count ondertekenaars opgeslagen. Kies in de editor per vak wie het invult.',
        'sent' => '{1}Contract verstuurd naar 1 persoon.|[2,*]Contract verstuurd naar :count mensen.',
        'reminded' => '{1}Herinnering verstuurd naar 1 persoon.|[2,*]Herinnering verstuurd naar :count mensen.',
        'nobody_to_remind' => 'Niemand om te herinneren: iedereen heeft getekend, of is vandaag al gemaand.',
        'duplicated' => 'Nieuw concept klaargezet met dezelfde PDF en vakken. Kies hieronder wie het tekent.',
        'copy_sent' => '{1}Het ondertekende document is naar 1 persoon gestuurd.|[2,*]Het ondertekende document is naar :count mensen gestuurd.',
        'nobody_to_send_copy' => 'Niemand om het naar te sturen: er heeft nog niemand getekend.',

        // Een sjabloon wordt nooit verstuurd, dus "klaargezet" zou het verkeerde
        // beeld geven: er gaat niets de deur uit, er komt iets op de plank.
        'template_created' => 'Sjabloon aangemaakt. Stel in naar hoeveel mensen het gaat en zet de invulvakken op de pagina\'s.',
        'template_recipients' => '{1}Dit sjabloon gaat straks naar 1 persoon.|[2,*]Dit sjabloon gaat straks naar :count mensen.',
        'template_signing_along' => 'Je staat als eerste partij op dit sjabloon. Zet er nu je handtekening op, dan draagt elk contract dat eruit komt hem al.',
        'template_not_signing_along' => 'Je tekent niet meer mee. Je handtekening is van dit sjabloon gehaald en de vakken zijn een plaats opgeschoven.',
        'template_unchanged' => 'Dat stond al zo ingesteld.',
    ],

    'transfer' => [
        'created' => 'Bestanden klaargezet. De link staat in de lijst.',
        'withdrawn' => 'Verzending ingetrokken.',
        'link_withdrawn' => 'Link voor :email ingetrokken.',
    ],

    'secret' => [
        'withdrawn' => 'Verzoek ingetrokken.',
        'filled' => '{1}Bedankt, de waarde is opgeslagen. Je kunt hem niet meer bekijken.|[2,*]Bedankt, :count waarden zijn opgeslagen. Je kunt ze niet meer bekijken.',
    ],

    'invitation' => [
        'sent' => 'Uitnodiging verstuurd naar :email.',
        'resent' => 'Uitnodiging opnieuw verstuurd naar :email.',
        'withdrawn' => 'Uitnodiging voor :email ingetrokken.',
        'link_created' => 'Uitnodigingslink aangemaakt.',
        'link_withdrawn' => 'Uitnodigingslink ingetrokken.',
        // Na het aannemen van een uitnodiging of het volgen van een link.
        'welcome' => 'Welkom bij :workspace.',
    ],

    'member' => [
        'channels_updated' => 'De kanalen van :name zijn bijgewerkt.',
        // Apart, omdat "bijgewerkt" bij nul wijzigingen suggereert dat er iets
        // gebeurd is waar iemand naar kan zoeken.
        'channels_unchanged' => 'Er is niets veranderd aan de kanalen van :name.',

        /*
         * Elke tak een hele zin, ook al staat het eerste stuk er drie keer.
         * Het alternatief is een rolwissel-zin met een tweede zin eraan
         * geplakt, en aan elkaar geplakte tekst is in een taal met een andere
         * woordvolgorde niet meer te maken.
         */
        'role_changed' => '{0}:name is nu :role.|{1}:name is nu :role. Eén openbaar kanaal is daarbij losgekoppeld.|[2,*]:name is nu :role. :count openbare kanalen zijn daarbij losgekoppeld.',
        'removed' => ':name is uit de workspace verwijderd.',
    ],

    'settings' => [
        'saved' => 'Instellingen opgeslagen.',
        'permissions_saved' => 'Rechten opgeslagen.',
        'notifications_saved' => 'Notificaties opgeslagen.',
        'theme_saved' => 'Thema opgeslagen.',
        'mail_saved' => 'Mailinstellingen opgeslagen.',
        'mail_test_sent' => 'Testmail verstuurd naar :email.',
        'avatar_saved' => 'Foto opgeslagen.',
        'avatar_removed' => 'Foto verwijderd.',
        'logo_saved' => 'Logo opgeslagen.',
        'logo_removed' => 'Logo verwijderd.',
    ],

    'rule' => [
        'added' => 'Regel toegevoegd.',
        'updated' => 'Regel bijgewerkt.',
        'removed' => 'Regel verwijderd.',
    ],

    'role' => [
        'created' => 'De rol :name is aangemaakt.',
        'saved' => 'De rol :name is opgeslagen.',
        'deleted' => 'De rol :name is verwijderd.',
    ],

    'custom_emoji' => [
        'added' => ':name staat nu in de picker.',
        'removed' => ':name is weggehaald.',
    ],

    'timeclock' => [
        'clocked_in' => 'Je staat ingeklokt.',
        'clocked_out' => 'Uitgeklokt na :duration.',
        'not_clocked_in' => 'Je stond niet ingeklokt.',
        'added' => 'De periode is toegevoegd.',
        'adjusted' => 'De periode is bijgesteld.',
        'removed' => 'De periode is verwijderd.',
    ],
];
