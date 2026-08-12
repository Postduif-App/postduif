<?php

/*
 * The names and one-line explanations a beheerder reads when switching a
 * feature on. Keyed by the feature's own name in kebab-case, which is the same
 * string Pennant stores — so a key here can never drift from the flag it
 * describes without the flag itself being renamed.
 */

return [
    'ai-access' => [
        'label' => 'AI-toegang',
        'description' => 'AI-clients mogen met een token meelezen en meepraten in deze workspace.',
    ],
    'contracts' => [
        'label' => 'Contracten',
        'description' => 'Een PDF met invulvakken die je laat ondertekenen. Ontvangers krijgen een eigen link per mail, vullen in en tekenen; de ondertekende versie komt terug bij de aanvrager.',
    ],

    'documents' => [
        'label' => 'Documenten',
        'description' => 'Kanalen kunnen documenten bijhouden naast het gesprek, met een editor die als Notion werkt.',
    ],
    'forms' => [
        'label' => 'Formulieren',
        'description' => 'Vragenlijsten die in een kanaal geplaatst of als link gedeeld kunnen worden. De antwoorden gaan per DM naar wie het formulier maakte.',
    ],
    'invite-links' => [
        'label' => 'Uitnodigingslinks',
        'description' => 'Meedoen via een deelbare link, naast een uitnodiging op naam.',
    ],
    'message-board' => [
        'label' => 'Prikbord',
        'description' => 'Een lijst met mededelingen voor de hele workspace, waar leden op kunnen reageren. Gasten zien het prikbord niet.',
    ],
    'message-forwarding' => [
        'label' => 'Berichten doorsturen',
        'description' => 'Een bericht uit het ene kanaal in het andere plaatsen, met de herkomst erbij.',
    ],
    'huddles' => [
        'label' => 'Huddles',
        'description' => 'Met een klik even praten in een kanaal, zonder een vergadering te plannen. Vraagt een relayserver om buiten kantoornetwerken te werken.',
    ],

    'polls' => [
        'label' => 'Polls',
        'description' => 'Een vraag met antwoorden in een kanaal zetten, waar iedereen op kan stemmen.',
    ],
    'saved-messages' => [
        'label' => 'Bewaarde berichten',
        'description' => 'Leden kunnen berichten bewaren en later in één lijst terugvinden.',
    ],
    'scheduled-messages' => [
        'label' => 'Geplande berichten',
        'description' => 'Leden kunnen een bericht klaarzetten dat later vanzelf verstuurd wordt.',
    ],
    'secret-requests' => [
        'label' => 'Geheimen',
        'description' => 'Wachtwoorden en sleutels opvragen via een formulier, en er zelf één versturen die de ontvanger precies één keer kan bekijken — in plaats van ze in een gesprek te laten plakken.',
    ],
    'timeclock' => [
        'label' => 'Tijdregistratie',
        'description' => 'Leden klokken in en uit, zien hun eigen uren terug en kunnen een geklokte periode achteraf bijstellen. Wie de uren van collega\'s mag inzien, bepaalt de workspace met een recht op de rol.',
    ],
    'tickets' => [
        'label' => 'Tickets',
        'description' => 'Kanalen kunnen een ticketlijst voeren voor werk dat nog openstaat.',
    ],
    'transfers' => [
        'label' => 'Bestanden versturen',
        'description' => 'Bestanden klaarzetten achter een deelbare downloadlink, ook voor mensen buiten de workspace.',
    ],
    'webhooks' => [
        'label' => 'Webhooks',
        'description' => 'Andere systemen mogen via een geheime URL in een kanaal posten.',
    ],
    'workflows' => [
        'label' => 'Workflows',
        'description' => 'De workspace kan zelf dingen doen: bij een trefwoord, een nieuw kanaallid of op een vast moment een reeks stappen aflopen. De stappen draaien met de rechten van wie de workflow schreef.',
    ],
];
