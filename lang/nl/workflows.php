<?php

/*
 * The words a beheerder reads while building a workflow.
 *
 * Keyed by the trigger's or action's own key, which is the same string the
 * workflow stores — so a line here cannot drift from the thing it describes
 * without the class itself being renamed.
 *
 * The "provides" block is shared on purpose: half the triggers hand over a
 * message and a channel, and describing those once means the variable picker
 * says the same thing wherever somebody meets it.
 */

return [

    'triggers' => [

        'message-keyword' => [
            'label' => 'Als iemand een woord zegt',
            'description' => 'Loopt zodra er een bericht geplaatst wordt waar een van jouw woorden in staat.',
            'keywords' => [
                'label' => 'Woorden',
                'hint' => 'Eén per keer. Hoofdletters maken niet uit.',
            ],
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Leeg laten betekent: overal in deze workspace.',
            ],
        ],

        'channel-join' => [
            'label' => 'Als iemand lid wordt van een kanaal',
            'description' => 'Loopt zodra er iemand bij een kanaal komt. Ook als diegene er eerder al eens in zat.',
            'channel' => [
                'label' => 'Welk kanaal',
                'hint' => 'Leeg laten betekent: elk kanaal in deze workspace.',
            ],
        ],

        'timeclock' => [
            'label' => 'Als iemand in- of uitklokt',
            'description' => 'Loopt zodra iemand zichzelf op de klok zet of eraf haalt. Alleen in workspaces waar tijdregistratie aanstaat.',
            'direction' => [
                'label' => 'Waarop',
                'hint' => 'Bij "beide" loopt de workflow twee keer per dienst: één keer aan het begin en één keer aan het eind.',
                'both' => 'In- én uitklokken',
                'in' => 'Alleen inklokken',
                'out' => 'Alleen uitklokken',
            ],
        ],

        'contract' => [
            'channel' => [
                'label' => 'In welk meldkanaal',
                'hint' => 'Alleen contracten waarvan het nieuws in dit kanaal komt. Leeg laten betekent: alle contracten.',
            ],
            'author' => [
                'label' => 'Van wie',
                'hint' => 'Alleen contracten die deze collega heeft aangevraagd. Leeg laten betekent: van iedereen.',
            ],
            'words' => [
                'label' => 'Woorden in de titel',
                'hint' => 'Eén van deze woorden is genoeg. Leeg laten betekent: elke titel.',
            ],
        ],

        'contract-sent' => [
            'label' => 'Als een contract verstuurd wordt',
            'description' => 'Loopt zodra een contract de deur uit gaat naar de ondertekenaars.',
        ],
        'contract-opened' => [
            'label' => 'Als een ondertekenaar het contract opent',
            'description' => 'Loopt de eerste keer dat iemand zijn link volgt. Eén keer per ondertekenaar.',
        ],
        'contract-signed' => [
            'label' => 'Als iemand een contract tekent',
            'description' => 'Loopt bij elke handtekening, ook de laatste. Wacht je op "iedereen is langs geweest", neem dan het afgeronde contract.',
        ],
        'contract-declined' => [
            'label' => 'Als iemand een contract weigert',
            'description' => 'Loopt zodra een ondertekenaar nee zegt. Daarmee is het hele contract klaar.',
        ],
        'contract-completed' => [
            'label' => 'Als een contract afgerond is',
            'description' => 'Loopt als iedereen geantwoord heeft én de ondertekende PDF klaarstaat, dus de downloadlink werkt meteen.',
        ],
        'contract-cancelled' => [
            'label' => 'Als een contract ingetrokken wordt',
            'description' => 'Loopt zodra de aanvrager een lopend contract stopzet.',
        ],
        'contract-expired' => [
            'label' => 'Als een contract verloopt',
            'description' => 'Loopt als de einddatum voorbij is zonder dat iedereen tekende. Wordt \'s nachts vastgesteld.',
        ],
        'contract-render-failed' => [
            'label' => 'Als de ondertekende PDF niet gemaakt kon worden',
            'description' => 'Loopt als het samenstellen van de getekende versie na alle pogingen mislukt. Het contract zelf is gewoon getekend.',
        ],

        'ticket' => [
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Alleen tickets uit dit kanaal. Leeg laten betekent: alle kanalen van deze workspace.',
            ],
        ],

        'ticket-created' => [
            'label' => 'Als er een ticket bijkomt',
            'description' => 'Loopt zodra iemand een ticket aanmaakt, ook als dat via e-mail binnenkomt.',
        ],
        'ticket-changed' => [
            'label' => 'Als een ticket verandert',
            'description' => 'Loopt bij een nieuwe status, een andere prioriteit, een toewijzing of een gewijzigde einddatum.',
            'kind' => [
                'label' => 'Waarop',
                'hint' => 'Bij "elke wijziging" loopt de workflow ook als alleen de einddatum verschuift.',
                'any' => 'Elke wijziging',
                'status' => 'Alleen de status',
                'priority' => 'Alleen de prioriteit',
                'assignee' => 'Alleen toewijzen of vrijgeven',
                'due' => 'Alleen de einddatum',
            ],
        ],
        'ticket-commented' => [
            'label' => 'Als er op een ticket gereageerd wordt',
            'description' => 'Loopt bij elke reactie. Of het de eerste reactie was, staat in de variabelen.',
        ],
        'ticket-stale' => [
            'label' => 'Als een ticket blijft liggen',
            'description' => 'Loopt bij de nachtelijke controle, hooguit één keer per dag per ticket.',
            'reason' => [
                'label' => 'Waarop',
                'hint' => '"Nooit beantwoord" is het geval dat de klant het eerst merkt.',
                'any' => 'Allebei',
                'overdue' => 'Over de einddatum',
                'unanswered' => 'Nooit beantwoord',
            ],
        ],

        'document' => [
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Alleen documenten uit dit kanaal. Leeg laten betekent: alle kanalen.',
            ],
        ],
        'document-created' => [
            'label' => 'Als er een document bijkomt',
            'description' => 'Loopt zodra iemand een document begint. Niet bij het opslaan daarna — dat gebeurt vanzelf om de paar seconden.',
        ],
        'document-deleted' => [
            'label' => 'Als een document weggehaald wordt',
            'description' => 'Loopt zodra iemand een document uit het kanaal haalt. Het document blijft bewaard, alleen niet meer zichtbaar.',
        ],

        'poll' => [
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Alleen polls uit dit kanaal. Leeg laten betekent: alle kanalen.',
            ],
        ],
        'poll-created' => [
            'label' => 'Als er een poll bijkomt',
            'description' => 'Loopt zodra iemand een vraag aan een kanaal stelt.',
        ],
        'poll-voted' => [
            'label' => 'Als iemand op een poll stemt',
            'description' => 'Loopt bij elke stem, ook als iemand zijn stem weer weghaalt. Met een conditie op het aantal stemmen bouw je hier een drempel mee.',
        ],
        'poll-closed' => [
            'label' => 'Als een poll gesloten wordt',
            'description' => 'Loopt als iemand een poll stopzet. Een poll die vanzelf verloopt geeft geen signaal.',
        ],

        'channel-share-offered' => [
            'label' => 'Als een andere workspace een kanaal met ons deelt',
            'description' => 'Loopt bij ons zodra iemand anders een kanaal aanbiedt. Er staat dan een uitnodiging klaar die beantwoord moet worden.',
        ],
        'channel-share-answered' => [
            'label' => 'Als er op ons gedeelde kanaal geantwoord wordt',
            'description' => 'Loopt bij ons zodra de andere workspace ja of nee zegt op een kanaal dat wij aanboden.',
            'answer' => [
                'label' => 'Waarop',
                'hint' => 'Bij "allebei" loopt de workflow ook als ze nee zeggen.',
                'any' => 'Allebei',
                'accepted' => 'Alleen bij ja',
                'declined' => 'Alleen bij nee',
            ],
        ],
        'channel-share-revoked' => [
            'label' => 'Als een gedeeld kanaal ingetrokken wordt',
            'description' => 'Loopt bij ons zodra de andere workspace een kanaal terugneemt. Onze mensen zitten er dan al niet meer in.',
        ],
        'invite-link-redeemed' => [
            'label' => 'Als iemand via een uitnodigingslink binnenkomt',
            'description' => 'Loopt zodra iemand nieuw lid wordt via een link. Iemand die al lid was, telt niet.',
            'role' => [
                'label' => 'Met welke rol',
                'hint' => 'De naam van de rol die de link uitdeelt. Leeg laten betekent: elke link.',
            ],
        ],
        'transfer-downloaded' => [
            'label' => 'Als een verzending opgehaald wordt',
            'description' => 'Loopt zodra iemand bestanden downloadt die je verstuurd hebt. Alleen dát het gebeurde — nooit wat erin zat.',
        ],
        'secret-request-answered' => [
            'label' => 'Als een geheimenverzoek ingevuld wordt',
            'description' => 'Loopt zodra iemand gegevens invult. Alleen hoeveel er ingevuld is; de waarden zijn versleuteld en onleesbaar voor Postduif.',
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Alleen verzoeken uit dit kanaal. Leeg laten betekent: alle kanalen.',
            ],
        ],

        'reaction' => [
            'label' => 'Als iemand een emoji gebruikt',
            'description' => 'Loopt zodra deze emoji op een bericht gezet wordt. Weghalen en opnieuw zetten laat hem opnieuw lopen.',
            'emoji' => [
                'label' => 'Welke emoji',
                'hint' => 'Alleen deze ene zet de workflow in gang.',
            ],
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Leeg laten betekent: overal in deze workspace.',
            ],
        ],

        'form-submitted' => [
            'label' => 'Als iemand een formulier instuurt',
            'description' => 'Loopt zodra er een inzending binnenkomt op het formulier dat je kiest.',
            'form' => [
                'label' => 'Welk formulier',
                'hint' => 'Eén formulier, want de antwoorden heten per formulier anders.',
            ],
            'anonymous' => 'anoniem',
        ],

        'link' => [
            'label' => 'Als iemand hem zelf start',
            'description' => 'Verschijnt in het berichtmenu. Wie hem daar kiest, start hem met dat bericht erbij.',
        ],

        'button' => [
            'label' => 'Als iemand op een knop drukt',
            'description' => 'Verschijnt als knop in de balk boven een kanaal. Je zet hem daar neer bij de instellingen van dat kanaal.',
        ],

        'slash-command' => [
            'label' => 'Als iemand een commando typt',
            'description' => 'Verschijnt in de lijst achter "/" in het berichtveld. Wat er achter het commando staat gaat als tekst mee.',
            'command' => [
                'label' => 'Commando',
                'hint' => 'Zonder schuine streep. Kleine letters, cijfers en streepjes, bijvoorbeeld storing-melden.',
                'malformed' => 'Een commando bestaat uit kleine letters, cijfers en streepjes, en begint met een letter of cijfer.',
                'reserved' => '/:command is al een vast commando in het berichtveld.',
                'taken' => '/:command is al van een andere workflow.',
            ],
        ],

        'webhook' => [
            'label' => 'Als er iets binnenkomt op een URL',
            'description' => 'Je krijgt een geheime URL. Alles wat daarnaartoe gestuurd wordt, zet de workflow in gang.',
        ],

        'schedule' => [
            'label' => 'Op een vast moment',
            'description' => 'Loopt vanzelf, op het ritme dat je kiest. De tijd is jouw eigen klok, dus de tijdzone die in je profiel staat.',
            'cadence' => [
                'label' => 'Hoe vaak',
                'hourly' => 'Elk uur',
                'daily' => 'Elke dag',
                'weekly' => 'Elke week',
            ],
            'time' => [
                'label' => 'Hoe laat',
                'hint' => 'Als 09:00. Bij elk uur hoef je dit niet in te vullen.',
            ],
            'weekday' => [
                'label' => 'Op welke dag',
                'hint' => 'Alleen nodig als het elke week is.',
            ],
        ],
    ],

    /*
     * What a run says about itself when it stopped early. Read on the
     * run-screen by whoever is wondering why nothing happened, so each of these
     * has to name the thing they can go and change.
     */
    'actions' => [
        'create-invite-link' => [
            'label' => 'Uitnodigingslink maken',
            'description' => 'Maakt een link om iemand binnen te laten en zet het adres klaar voor een volgende stap. Verstuurt zelf niets.',
            'role' => [
                'label' => 'Welke rol',
                'hint' => 'De naam van een rol in deze workspace, bijvoorbeeld "Gast".',
            ],
            'uses' => [
                'label' => 'Hoe vaak bruikbaar',
                'hint' => 'Leeg laten betekent: onbeperkt. Eén is de veiligste keuze.',
            ],
            'days' => [
                'label' => 'Geldig hoeveel dagen',
                'hint' => 'Leeg laten betekent: blijft werken tot je hem intrekt.',
            ],
        ],
        'create-secret-request' => [
            'label' => 'Om gegevens vragen',
            'description' => 'Zet een verzoek om inloggegevens in een kanaal. Wat er ingevuld wordt, is versleuteld en onleesbaar voor Postduif.',
            'title' => [
                'label' => 'Waar het over gaat',
                'hint' => 'Bijvoorbeeld: toegang tot de webshop.',
            ],
            'keys' => [
                'label' => 'Waar je om vraagt',
                'hint' => 'Eén per veld, gescheiden door komma\'s. Hier kunnen geen variabelen in.',
            ],
            'days' => [
                'label' => 'Geldig hoeveel dagen',
                'hint' => 'Leeg laten betekent veertien dagen.',
            ],
        ],
        'post-to-board' => [
            'label' => 'Op het prikbord zetten',
            'description' => 'Plaatst een bericht op het prikbord van de workspace, op naam van de eigenaar van deze workflow.',
            'title' => ['label' => 'Kop'],
        ],
        'forward-message' => [
            'label' => 'Bericht doorsturen',
            'description' => 'Stuurt het bericht van de trigger door naar een ander kanaal, met vermelding van wie het schreef.',
            'note' => [
                'label' => 'Notitie erboven',
                'hint' => 'Leeg laten mag; dan gaat alleen het bericht mee.',
            ],
        ],
        'clock-out' => [
            'label' => 'Iemand uitklokken',
            'description' => 'Sluit de dienst die nog liep. Stond er niets open, dan gebeurt er niets.',
            'person' => [
                'label' => 'Wie',
                'hint' => 'Leeg laten betekent: degene waar de trigger over ging.',
            ],
        ],
        'summarise-hours' => [
            'label' => 'Uren optellen',
            'description' => 'Telt een week op en zet het resultaat klaar voor een volgende stap. Verstuurt zelf niets.',
            'person' => [
                'label' => 'Van wie',
                'hint' => 'Leeg laten betekent: degene waar de trigger over ging.',
            ],
            'week' => [
                'label' => 'Welke week',
                'hint' => 'Een overzicht dat je maandag stuurt, gaat meestal over de week ervoor.',
                'this' => 'Deze week',
                'last' => 'Vorige week',
            ],
        ],
        'create-document' => [
            'label' => 'Document beginnen',
            'description' => 'Begint een leeg document in een kanaal. Vullen doe je met de volgende stap.',
            'title' => [
                'label' => 'Titel',
                'hint' => 'Je kunt hier gegevens uit de trigger in zetten.',
            ],
        ],
        'append-to-document' => [
            'label' => 'Regel aan een document toevoegen',
            'description' => 'Zet een alinea onderaan een document. Handig als logboek.',
            'text' => [
                'label' => 'Wat erbij komt',
                'hint' => 'Eén alinea, onderaan. Je kunt hier gegevens uit de trigger in zetten.',
            ],
        ],
        'create-poll' => [
            'label' => 'Poll starten',
            'description' => 'Stelt een vraag aan een kanaal, met minstens twee antwoorden.',
            'question' => [
                'label' => 'De vraag',
                'hint' => 'Je kunt hier gegevens uit de trigger in zetten.',
            ],
            'options' => [
                'label' => 'De antwoorden',
                'hint' => 'Minstens twee, gescheiden door komma\'s. Hier kunnen geen variabelen in.',
            ],
            'multiple' => [
                'label' => 'Meerdere antwoorden toestaan',
                'no' => 'Eén antwoord per persoon',
                'yes' => 'Meerdere antwoorden mogen',
            ],
            'closes' => [
                'label' => 'Sluit na hoeveel uur',
                'hint' => 'Leeg laten betekent: blijft open tot iemand hem stopt.',
            ],
        ],
        'close-poll' => [
            'label' => 'Poll sluiten',
            'description' => 'Stopt een poll, zodat er niet meer gestemd kan worden.',
        ],
        'update-ticket' => [
            'label' => 'Ticket bijwerken',
            'description' => 'Zet de status, de prioriteit en/of de einddatum. Wat je leeg laat, blijft zoals het was.',
            'leave_alone' => 'Leeg laten betekent: niet aanpassen.',
            'status' => ['label' => 'Nieuwe status'],
            'priority' => ['label' => 'Nieuwe prioriteit'],
            'due' => [
                'label' => 'Einddatum over hoeveel dagen',
                'hint' => 'Geteld vanaf het moment dat deze stap loopt.',
            ],
        ],
        'assign-ticket' => [
            'label' => 'Ticket toewijzen',
            'description' => 'Geeft het ticket aan een collega, of haalt het weg bij wie het had.',
            'person' => [
                'label' => 'Aan wie',
                'hint' => 'Leeg laten haalt het ticket weg bij degene die het had.',
            ],
        ],
        'comment-on-ticket' => [
            'label' => 'Op een ticket reageren',
            'description' => 'Zet een reactie op het ticket, op naam van de eigenaar van deze workflow.',
        ],
        'send-contract-from-template' => [
            'label' => 'Contract versturen uit een sjabloon',
            'description' => 'Maakt een contract uit een sjabloon en stuurt het naar één persoon. Het sjabloon is al door jouw kant getekend.',
            'template' => [
                'label' => 'Welk sjabloon',
                'hint' => 'Alleen afgeronde sjablonen kunnen verstuurd worden.',
            ],
            'name' => [
                'label' => 'Naam van de ondertekenaar',
                'hint' => 'Mag een variabele zijn, bijvoorbeeld het antwoord uit een formulier.',
            ],
            'email' => [
                'label' => 'E-mailadres van de ondertekenaar',
                'hint' => 'Hier gaat de uitnodiging naartoe. Mag een variabele zijn.',
            ],
            'title' => [
                'label' => 'Titel van het contract',
                'hint' => 'Leeg laten betekent: de titel van het sjabloon.',
            ],
            'days' => [
                'label' => 'Geldig hoeveel dagen',
                'hint' => 'Leeg laten betekent: wat het sjabloon zelf aanhoudt.',
            ],
            'channel' => [
                'label' => 'Meldkanaal',
                'hint' => 'Hier komt het nieuws over dit contract. Leeg laten mag.',
            ],
        ],
        'remind-contract-signers' => [
            'label' => 'Ondertekenaars herinneren',
            'description' => 'Stuurt de uitnodiging nog eens naar iedereen die nog niet geantwoord heeft. Wie in de afgelopen dag al een herinnering kreeg, wordt overgeslagen.',
        ],
        'post-contract-to-channel' => [
            'label' => 'Contract in een kanaal plaatsen',
            'description' => 'Plaatst de contractkaart in een kanaal, op naam van de eigenaar van deze workflow.',
        ],
        'add-contract-signer' => [
            'label' => 'Ondertekenaar toevoegen',
            'description' => 'Zet er nog iemand op, zolang het contract nog niet verstuurd is.',
            'name' => [
                'label' => 'Naam',
                'hint' => 'Mag uit een variabele komen, bijvoorbeeld {{ trigger.answers.naam }}.',
            ],
            'email' => [
                'label' => 'E-mailadres',
                'hint' => 'Waar de vraag om te tekenen naartoe gaat.',
            ],
        ],
        'duplicate-contract' => [
            'label' => 'Contract dupliceren',
            'description' => 'Maakt een nieuw concept van hetzelfde document, zonder de ondertekenaars en handtekeningen van het origineel.',
            'title' => [
                'label' => 'Titel van de kopie',
                'hint' => 'Een contract wordt maar één keer benoemd, dus geef de kopie een eigen naam — bijvoorbeeld Huurovereenkomst {{ trigger.answers.naam }}.',
            ],
        ],
        'cancel-contract' => [
            'label' => 'Contract intrekken',
            'description' => 'Zet een lopend contract stop. De links blijven werken en vertellen dat het ingetrokken is.',
        ],
        'send-signed-contract' => [
            'label' => 'Ondertekende kopie versturen',
            'description' => 'Mailt iedereen die tekende de ondertekende PDF.',
            'again' => [
                'label' => 'Ook naar wie hem al had',
                'hint' => 'Kies "opnieuw" als iemand zijn kopie kwijt is.',
                'no' => 'Alleen wie hem nog niet had',
                'yes' => 'Opnieuw naar iedereen',
            ],
        ],
        'retry-contract-render' => [
            'label' => 'Ondertekende PDF opnieuw proberen',
            'description' => 'Zet het samenstellen van de getekende versie nog eens in de wachtrij.',
        ],

        'fields' => [
            'channel' => 'Welk kanaal',
            'person' => 'Wie',
            'body' => 'Wat er komt te staan',
            'body_hint' => 'Je kunt hier gegevens uit de trigger in zetten.',
            'message' => 'Welk bericht',
            'message_hint' => 'Leeg laten betekent: het bericht waar de trigger over ging.',
            'contract' => 'Welk contract',
            'contract_hint' => 'Leeg laten betekent: het contract waar de trigger over ging.',
            'ticket' => 'Welk ticket',
            'ticket_hint' => 'Leeg laten betekent: het ticket waar de trigger over ging.',
            'document' => 'Welk document',
            'document_hint' => 'Leeg laten betekent: het document waar de trigger over ging.',
            'poll' => 'Welke poll',
            'poll_hint' => 'Leeg laten betekent: de poll waar de trigger over ging.',
            'added' => 'Of er echt iemand is toegevoegd',
            'thread' => [
                'id' => 'De thread waar het antwoord in kwam',
            ],
            'emoji' => 'Welke emoji',
            'reminded' => 'Hoeveel er een herinnering kregen',
            'copies_sent' => 'Hoeveel kopieën verstuurd zijn',
            'channel_name' => 'Naam van het kanaal',
            'channel_name_hint' => 'Mag gegevens uit de trigger bevatten, bijvoorbeeld de naam van wie het aanvroeg.',
            'channel_type' => 'Wie mag erbij',
            'topic' => 'Onderwerp',
        ],

        'send-channel-message' => [
            'label' => 'Bericht in een kanaal',
            'description' => 'Plaatst een bericht onder de naam van deze workflow, herkenbaar als bot.',
        ],
        'send-direct-message' => [
            'label' => 'Bericht aan een persoon',
            'description' => 'Stuurt een DM. Het gesprek loopt via de eigenaar van deze workflow.',
        ],
        'reply-in-thread' => [
            'label' => 'Antwoord in een thread',
            'description' => 'Hangt een antwoord onder een bericht in plaats van ernaast.',
        ],
        'create-ticket' => [
            'label' => 'Ticket openen',
            'description' => 'Zet werk op het ticketbord van een kanaal, op naam van wie deze workflow schreef.',
            'title' => [
                'label' => 'Waar gaat het ticket over',
                'hint' => 'De regel die op het bord komt te staan. Je kunt hier gegevens uit de trigger in zetten.',
            ],
            'body' => [
                'label' => 'De omschrijving',
            ],
            'priority' => 'Hoe urgent',
        ],
        'add-reaction' => [
            'label' => 'Emoji op een bericht zetten',
            'description' => 'Reageert namens de eigenaar van deze workflow.',
        ],
        'remove-reaction' => [
            'label' => 'Emoji weghalen',
            'description' => 'Haalt alleen de reactie van de eigenaar van deze workflow weg.',
        ],
        'pin-message' => [
            'label' => 'Bericht vastzetten',
            'description' => 'Zet een bericht bovenaan het kanaal.',
        ],
        'unpin-message' => [
            'label' => 'Bericht losmaken',
            'description' => 'Haalt een bericht weer van de vastgezette lijst af.',
        ],
        'create-channel' => [
            'label' => 'Kanaal aanmaken',
            'description' => 'Opent een nieuw kanaal. De volgende stappen kunnen er meteen bij.',
            'public' => 'Iedereen in de workspace',
            'private' => 'Alleen wie je toevoegt',
        ],
        'add-channel-members' => [
            'label' => 'Iemand aan een kanaal toevoegen',
            'description' => 'Zet één persoon in een kanaal. Was diegene er al, dan gebeurt er niets.',
        ],
        'get-channel-info' => [
            'label' => 'Kanaalgegevens ophalen',
            'description' => 'Verandert niets, maar zet naam, onderwerp en ledenaantal klaar voor een volgende stap.',
        ],
        'archive-channel' => [
            'label' => 'Kanaal archiveren',
            'description' => 'Sluit een kanaal. Alles blijft leesbaar, niemand kan er nog posten.',
        ],
        'unarchive-channel' => [
            'label' => 'Kanaal weer openen',
            'description' => 'Haalt een kanaal uit het archief.',
        ],
        'http-request' => [
            'label' => 'HTTP-verzoek doen',
            'description' => 'Vraagt iets aan een ander systeem en onthoudt het antwoord, zodat een volgende stap ermee verder kan.',
            'method' => [
                'label' => 'Wat voor verzoek',
            ],
            'url' => [
                'label' => 'Naar welke URL',
                'hint' => 'Moet met https:// beginnen en buiten dit netwerk staan. Je mag er gegevens uit eerdere stappen in zetten.',
            ],
            'headers' => [
                'label' => 'Headers',
                'hint' => 'Eén per regel, als "Authorization: Bearer abc". Hier zet je meestal je sleutel.',
            ],
            'body' => [
                'label' => 'Wat je meestuurt',
                'hint' => 'Meestal JSON. Blijft leeg bij een GET. Gegevens uit eerdere stappen mogen erin.',
            ],
        ],
        'delay' => [
            'label' => 'Wachten',
            'description' => 'Zet de workflow stil en pakt hem later weer op.',
            'minutes' => [
                'label' => 'Hoeveel minuten',
                'hint' => 'Een uur is 60, een dag 1440. Maximaal vier weken.',
            ],
        ],
    ],

    /*
     * What goes wrong, in words the person who wrote the workflow can act on.
     * Every one of these ends up on the run screen, so "kanaal niet gevonden"
     * has to be a complete answer there rather than the start of a hunt.
     */
    'config' => [
        'no_variables' => 'In dit veld kun je geen variabele gebruiken.',
        'not_text' => 'Dit veld verwacht tekst.',
        'too_long' => 'Dit veld mag hoogstens :max tekens lang zijn.',
        'not_a_number' => 'Dit veld verwacht een getal.',
        'not_words' => 'Dit veld verwacht een lijst woorden.',
        'too_many_words' => 'Hoogstens :max woorden.',
        'unknown_choice' => 'Kies een van de mogelijkheden uit de lijst.',
        'channel_not_found' => 'Dat kanaal hoort niet bij deze workspace.',
        'member_not_found' => 'Die persoon zit niet in deze workspace.',
        'form_not_found' => 'Dat formulier hoort niet bij deze workspace.',
        'record_not_found' => 'Dat is geen :what dat je hier kunt kiezen.',
    ],
    'errors' => [
        'board_off' => 'Deze workspace heeft geen prikbord meer.',
        'forwarding_off' => 'Deze workspace stuurt geen berichten meer door.',
        'secrets_off' => 'Deze workspace vraagt niet meer om gegevens.',
        'invite_links_off' => 'Deze workspace werkt niet meer met uitnodigingslinks.',
        'empty_board_post' => 'Een prikbordbericht heeft een kop én tekst nodig.',
        'empty_secret_request' => 'Een verzoek heeft een omschrijving nodig en minstens één ding waar je om vraagt.',
        'may_not_ask_secrets' => 'De eigenaar van deze workflow mag niets plaatsen in #:channel.',
        'may_not_invite' => 'De eigenaar van deze workflow mag niemand uitnodigen.',
        'no_role_named' => 'Er is geen rol opgegeven voor de uitnodigingslink.',
        'role_not_found' => 'Deze workspace heeft geen rol die ":role" heet.',
        'timeclock_off' => 'Deze workspace houdt geen tijd meer bij.',
        'no_person_anywhere' => 'Deze stap gaat over een persoon, maar er is er geen gekozen en de trigger bracht er ook geen mee.',
        'documents_off' => 'Deze workspace houdt geen documenten meer bij.',
        'polls_off' => 'Deze workspace houdt geen polls meer bij.',
        'may_not_create_document' => 'De eigenaar van deze workflow mag geen document beginnen in #:channel.',
        'may_not_write_document' => 'De eigenaar van deze workflow mag niet schrijven in ":title".',
        'may_not_close_poll' => 'De eigenaar van deze workflow mag deze poll niet sluiten.',
        'empty_document_title' => 'Er bleef geen titel over voor het document.',
        'empty_document_text' => 'Er bleef geen tekst over om aan het document toe te voegen.',
        'document_busy' => 'Er wordt op dit moment in ":title" geschreven, dus de regel is niet toegevoegd.',
        'empty_question' => 'Er bleef geen vraag over om te stellen.',
        'too_few_options' => 'Een poll heeft minstens twee antwoorden nodig.',
        'may_not_manage_ticket' => 'De eigenaar van deze workflow mag ticket #:number niet bijwerken.',
        'may_not_comment_on_ticket' => 'De eigenaar van deze workflow mag niet reageren op ticket #:number.',
        'assignee_cannot_see_ticket' => ':name kan dit ticket niet zien, dus kreeg het niet toegewezen.',
        'nothing_to_change' => 'Deze stap zou niets aanpassen: vul een status, een prioriteit of een einddatum in.',
        'empty_comment' => 'Er bleef geen tekst over om op het ticket te zetten.',
        'contracts_off' => 'Deze workspace vraagt geen handtekeningen meer.',
        'may_not_touch_contract' => 'De eigenaar van deze workflow mag dit niet doen met ":title".',
        'may_not_send_contract' => 'De eigenaar van deze workflow mag geen contracten versturen.',
        'template_unfinished' => 'Het sjabloon ":title" is nog niet af: er ontbreken vakken of een handtekening.',
        'template_wants_more_signers' => 'Het sjabloon ":title" is voor :count ondertekenaars, en deze stap stuurt er naar één.',
        'bad_signer_email' => '":email" is geen bruikbaar e-mailadres.',
        'no_signer_name' => 'Er bleef geen naam over voor de ondertekenaar.',
        'signer_is_sender' => 'Dat adres tekende het sjabloon zelf al (:email).',
        'signer_already_on' => ':email staat al op dit contract.',
        'contract_already_sent' => '":title" is al verstuurd, dus er kan niemand meer bij.',
        'nothing_to_duplicate' => 'Van ":title" is geen document om te kopiëren.',
        'no_contract_title' => 'Er bleef geen titel over voor de kopie.',
        'nothing_to_render' => 'Er valt niets samen te stellen voor ":title": het contract is nog niet afgerond.',
        'no_channel_chosen' => 'Deze stap heeft geen kanaal gekregen.',
        'channel_not_found' => 'Dat kanaal bestaat niet meer, of de eigenaar van deze workflow mag er niet bij.',
        'no_message' => 'Deze stap gaat over een bericht, maar er is er geen.',
        'message_not_found' => 'Dat bericht bestaat niet meer.',
        'no_person_chosen' => 'Deze stap heeft geen persoon gekregen.',
        'no_record' => 'Deze stap gaat over :what, maar er is er geen aangewezen en de trigger bracht er ook geen mee.',
        'record_not_found' => 'Dat is geen :what van deze workspace, of de eigenaar van deze workflow mag er niet bij.',
        'person_not_found' => 'Die persoon zit niet meer in deze workspace.',
        'tickets_off' => 'Deze workspace houdt geen tickets meer bij.',
        'may_not_open_ticket' => 'De eigenaar van deze workflow mag geen ticket openen in :channel.',
        'empty_ticket_title' => 'Er bleef niets over om het ticket naar te noemen.',
        'no_owner' => 'Deze workflow heeft geen eigenaar meer.',
        'may_not_post' => 'De eigenaar van deze workflow mag niet posten in #:channel.',
        'may_not_dm' => 'De eigenaar van deze workflow mag deze persoon geen bericht sturen.',
        'dm_to_self' => 'Deze stap wijst de eigenaar van de workflow zelf aan, en een DM met jezelf bestaat niet.',
        'may_not_pin' => 'De eigenaar van deze workflow mag hier niets vastzetten.',
        'may_not_archive' => 'De eigenaar van deze workflow mag dit kanaal niet archiveren.',
        'may_not_add_members' => 'De eigenaar van deze workflow mag hier niemand toevoegen.',
        'may_not_create_channel' => 'De eigenaar van deze workflow mag geen kanalen aanmaken.',
        'no_channel_name' => 'Er is geen naam voor het kanaal overgebleven.',
        'empty_message' => 'Er bleef geen tekst over om te versturen.',
        'url_unreadable' => 'Dat is geen adres waar Postduif iets mee kan.',
        'url_scheme' => 'Alleen http:// en https:// kunnen opgevraagd worden.',
        'url_not_public' => 'Dit adres ligt binnen het eigen netwerk van de server. Dat mag een workflow niet opvragen.',
        'url_unknown_host' => 'Dat adres bestaat niet, of het antwoordt op dit moment niet.',
        'http_method' => 'Dat soort verzoek kent Postduif niet.',
        'http_unreachable' => 'Er kwam geen antwoord. Het adres deed er te lang over of is niet bereikbaar.',
        'delay_too_short' => 'Wachten doe je minstens een minuut.',
        'delay_too_long' => 'Langer dan vier weken wachten kan niet.',
    ],

    'webhook' => [
        'unknown' => 'Onbekende workflow.',
    ],

    /* What the builder screen says back after a change. */
    'screen' => [
        'avatar' => 'Gezicht van de bot',
        'avatar_hint' => 'Staat naast elk bericht dat deze workflow plaatst. Zonder foto blijft het standaard botteken staan.',
        'avatar_choose' => 'Foto kiezen',
        'avatar_remove' => 'Foto weghalen',
        'avatar_saved' => 'Het gezicht van de bot is bijgewerkt.',
        'avatar_removed' => 'De foto is weggehaald.',
        'created' => 'Workflow aangemaakt. Zet hem aan zodra de stappen kloppen.',
        'saved' => 'Workflow opgeslagen.',
        'deleted' => 'Workflow verwijderd.',
        'too_many' => 'Meer dan :count workflows per workspace is te veel om te overzien.',
        'no_steps' => 'Deze workflow heeft nog geen stappen, dus er valt niets aan te zetten.',
        'too_many_steps' => 'Meer dan :count stappen in één workflow is te veel om te volgen.',
    ],

    /* What somebody sees after starting one from the message menu. */
    /* Wat je terugkrijgt na een commando in het berichtveld, alleen jij. */
    'command' => [
        'unknown' => 'Er is geen workflow die luistert naar /:command.',
    ],

    'link' => [
        'started' => '":name" is gestart.',
        'refused' => 'Deze workflow kon nu niet starten.',
    ],

    'run' => [
        'no_longer_allowed' => 'Deze workflow staat uit of heeft geen eigenaar meer, dus de rest is niet uitgevoerd.',
        'unknown_action' => 'Deze stap doet iets (:action) wat Postduif niet meer kent.',
        'step_failed' => 'Deze stap ging mis.',
        'went_round_in_circles' => 'Deze workflow loopt in een kring en is gestopt.',
    ],

    /*
     * How a value reads once it is part of a sentence. Only the two that have
     * no natural wording of their own: everything else is already text by the
     * time a step sees it.
     */
    'value' => [
        'yes' => 'ja',
        'no' => 'nee',
        // What is left where an answer was cut off, so half a sentence does
        // not read as the whole of one.
        'truncated' => '… (afgekapt)',
    ],

    'weekdays' => [
        1 => 'Maandag',
        2 => 'Dinsdag',
        3 => 'Woensdag',
        4 => 'Donderdag',
        5 => 'Vrijdag',
        6 => 'Zaterdag',
        7 => 'Zondag',
    ],

    'provides' => [
        'share' => [
            'id' => 'De deling',
            'can_post' => 'Of de gasten mogen meepraten',
            'accepted' => 'Of het aanbod aangenomen is',
            'channel_id' => 'Het gedeelde kanaal',
            'channel_name' => 'De naam van het gedeelde kanaal',
            'host_id' => 'De workspace die het kanaal bezit',
            'host_name' => 'De naam van die workspace',
            'guest_id' => 'De workspace die te gast is',
            'guest_name' => 'De naam van die workspace',
        ],
        'link' => [
            'id' => 'De uitnodigingslink',
            'url' => 'Het adres van de link',
            'role' => 'De rol die de link uitdeelt',
            'uses' => 'Hoe vaak de link gebruikt is',
            'uses_left' => 'Hoe vaak de link nog kan',
            'expires_at' => 'Tot wanneer de link werkt',
        ],
        'transfer' => [
            'id' => 'De verzending',
            'title' => 'De titel van de verzending',
            'downloads' => 'Hoe vaak er gedownload is',
            'expires_at' => 'Tot wanneer de verzending werkt',
            'sender_id' => 'Wie het verstuurde',
            'sender_name' => 'De naam van wie het verstuurde',
            'recipient_id' => 'De ontvanger die ophaalde',
            'recipient_email' => 'Het adres van die ontvanger',
        ],
        'secret' => [
            'id' => 'Het verzoek',
            'title' => 'Waar het verzoek over gaat',
            'url' => 'De link om het in te vullen',
            'answered' => 'Hoeveel er nu ingevuld is',
            'outstanding' => 'Hoeveel er nog open staat',
            'is_complete' => 'Of alles ingevuld is',
            'requester_id' => 'Wie erom vroeg',
            'requester_name' => 'De naam van wie erom vroeg',
        ],
        'board' => [
            'id' => 'Het prikbordbericht',
            'title' => 'De kop van het prikbordbericht',
        ],
        'hours' => [
            'total' => 'Het aantal uren in die week',
            'spoken' => 'Die uren als zin',
            'days_worked' => 'Op hoeveel dagen er gewerkt is',
            'from' => 'De maandag van die week',
            'until' => 'De zondag van die week',
        ],
        'document' => [
            'id' => 'Het document',
            'number' => 'Het documentnummer',
            'title' => 'De titel van het document',
            'actor_id' => 'Wie het deed',
            'actor_name' => 'De naam van wie het deed',
        ],
        'poll' => [
            'id' => 'De poll',
            'question' => 'De vraag',
            'url' => 'Link naar de poll',
            'option_count' => 'Aantal antwoorden',
            'vote_count' => 'Aantal stemmen',
            'voter_count' => 'Aantal mensen dat stemde',
            'leading_option' => 'Het antwoord dat voorligt',
            'top_votes' => 'Aantal stemmen op het antwoord dat voorligt',
            'is_closed' => 'Of de poll gesloten is',
            'closes_at' => 'Wanneer de poll sluit',
            'closed_now' => 'Of deze stap hem daadwerkelijk sloot',
            'asker_id' => 'Wie de vraag stelde',
            'asker_name' => 'De naam van wie de vraag stelde',
            'vote_ticked' => 'Of de stem erbij kwam of eraf ging',
            'option_id' => 'Het antwoord waarop gestemd werd',
            'option_label' => 'De tekst van dat antwoord',
            'option_votes' => 'Aantal stemmen op dat antwoord',
            'voter_id' => 'Wie er stemde',
            'voter_name' => 'De naam van wie er stemde',
        ],
        'contract' => [
            'id' => 'Het contract',
            'title' => 'De titel van het contract',
            'status' => 'De stand van het contract',
            'url' => 'Link naar het contract in Postduif',
            'download_url' => 'Link om de ondertekende PDF te downloaden',
            'expires_at' => 'De einddatum',
            'days_until_expiry' => 'Aantal dagen tot de einddatum',
            'page_count' => 'Aantal pagina\'s',
            'signer_count' => 'Aantal ondertekenaars',
            'signed_count' => 'Aantal dat getekend heeft',
            'declined_count' => 'Aantal dat geweigerd heeft',
            'remaining' => 'Aantal dat nog moet antwoorden',
            'signers' => 'De namen van de ondertekenaars',
            'author_id' => 'De aanvrager',
            'author_name' => 'De naam van de aanvrager',
            'channel_id' => 'Het meldkanaal',
            'channel_name' => 'De naam van het meldkanaal',
        ],
        'signer' => [
            'id' => 'De ondertekenaar',
            'name' => 'De naam van de ondertekenaar',
            'email' => 'Het e-mailadres van de ondertekenaar',
            'order' => 'De plek in de rij',
            'is_external' => 'Of het iemand van buiten is',
            'is_last' => 'Of dit de laatste was die moest antwoorden',
            'decline_reason' => 'De opgegeven reden van weigeren',
        ],
        'http' => [
            'status' => 'De statuscode van het antwoord',
            'ok' => 'Of het verzoek gelukt is',
            'json' => 'Het antwoord (JSON)',
            'body' => 'Het antwoord als tekst',
        ],
        'message' => [
            'id' => 'Het bericht',
            'text' => 'Wat er in het bericht staat',
        ],
        'ticket' => [
            'title' => 'De titel van het ticket',
            'body' => 'De omschrijving van het ticket',
            'status' => 'De status van het ticket',
            'priority' => 'De prioriteit',
            'due_at' => 'De einddatum',
            'hours_open' => 'Hoeveel uur het ticket al open staat',
            'is_overdue' => 'Of de einddatum voorbij is',
            'has_assignee' => 'Of er iemand op staat',
            'answered' => 'Of er al iemand gereageerd heeft',
            'assignee_id' => 'Wie het ticket heeft',
            'assignee_name' => 'De naam van wie het ticket heeft',
            'reporter_id' => 'Wie het ticket aanmaakte',
            'reporter_name' => 'De naam van wie het aanmaakte',
            'actor_id' => 'Wie deze wijziging deed',
            'actor_name' => 'De naam van wie deze wijziging deed',
            'change_kind' => 'Wat er veranderde',
            'change_from' => 'Wat het was',
            'change_to' => 'Wat het nu is',
            'comment_id' => 'De reactie',
            'comment_body' => 'De tekst van de reactie',
            'comment_first' => 'Of dit de eerste reactie was',
            'comment_author_id' => 'Wie reageerde',
            'comment_author_name' => 'De naam van wie reageerde',
            'stale_reason' => 'Waarom het bleef liggen',
            'id' => 'Het ticket',
            'number' => 'Het nummer van het ticket',
        ],
        'channel' => [
            'topic' => 'Het onderwerp van het kanaal',
            'members' => 'Hoeveel leden het kanaal heeft',
            'archived' => 'Of het kanaal gearchiveerd is',
            'id' => 'Het kanaal',
            'name' => 'De naam van het kanaal',
        ],
        'punch' => [
            'direction' => 'Of er in- of uitgeklokt werd',
            'at' => 'Hoe laat, op de klok van diegene zelf',
        ],
        'shift' => [
            'hours' => 'Hoe lang de dienst duurde, in uren (7,5)',
            'duration' => 'Hoe lang de dienst duurde, uitgeschreven',
            'started_at' => 'Hoe laat de dienst begon',
            'was_running' => 'Of er inderdaad een dienst open stond',
        ],
        'user' => [
            'id' => 'Wie het deed',
            'name' => 'De naam van wie het deed',
        ],
        'form' => [
            'id' => 'Het formulier',
            'title' => 'De naam van het formulier',
        ],
        'reactor' => [
            'id' => 'Wie de emoji zette',
            'name' => 'De naam van wie de emoji zette',
        ],
        'starter' => [
            'id' => 'Wie de workflow startte',
            'name' => 'De naam van wie de workflow startte',
        ],
        'author' => [
            'id' => 'Wie het bericht schreef',
            'name' => 'De naam van wie het bericht schreef',
        ],
        'moment' => [
            'date' => 'De datum van vandaag',
            'time' => 'Hoe laat het is',
        ],
        'command' => 'Het commando dat getypt werd, zonder de schuine streep',
        'arguments' => 'Wat er achter het commando stond',
        'emoji' => 'De emoji die gebruikt werd',
        'keyword' => 'Het woord dat gevonden werd',
        'answers' => 'Alle antwoorden. Eén los antwoord haal je op met de sleutel van de vraag erachter, bijvoorbeeld answers.reden',
        'payload' => 'Alles wat er binnenkwam',
    ],
];
