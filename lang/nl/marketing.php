<?php

/*
 * The public site: the shell around every page, and the copy on them.
 *
 * Here rather than inline in the components, because the site answers in the
 * reader's language — see HandleLocale, which picks it off Accept-Language for
 * somebody who has no account and so has never set anything.
 *
 * What comes from the application is deliberately not repeated here. Every
 * feature, role and channel setting the home page lists is read off the classes
 * that implement them and is already translated where those live; see
 * BuildFeatureInventory for why that is a rule rather than a convenience.
 */

return [
    'nav' => [
        'to_app' => 'Naar de app',
        'login' => 'Inloggen',
        'start' => 'Beginnen',
        'api' => 'API',
    ],

    'footer' => [
        'tagline' => 'Een werkplek voor gesprekken, het werk dat eruit volgt en de bestanden die erbij horen.',
        // De pijl zegt dat je de site verlaat; de link zelf opent in een tabblad.
        'source' => 'Broncode op GitHub ↗',
        // De uitgave waar deze site bij hoort. Een maandnaam, dus vertaald.
        'edition' => 'augustus 2026',
    ],

    'home' => [
        'eyebrow' => 'Gasten van buiten, zonder ze de rest te laten zien',
        // De titel in het tabblad; zonder punt, anders dan de kop op de pagina.
        'head' => 'Het gesprek en het werk op één plek',
        'headline' => 'Het gesprek en het werk op één plek.',
        'intro' => 'Kanalen en threads, tickets voor wat er blijft liggen, en bestanden die te groot zijn om mee te sturen. Klanten doen mee als gast en zien alleen hun eigen kanalen.',
        'cta_start' => 'Beginnen →',
        'cta_login' => 'Inloggen →',
        /*
         * Naast de knop in plaats van in de intro: dat het niets kost is voor
         * de meeste bezoekers het antwoord op de vraag die ze bij de knop
         * stellen, en de broncode is waar dat te controleren valt.
         */
        'source' => 'Gratis en open source — broncode op GitHub ↗',

        /*
         * De vier getallen onder de hero, als eenheid onder een cijfer.
         *
         * Geen zinnen met :count erin: het getal staat er groot naast en
         * herhalen zou het twee keer zeggen. Daarom ook geen meervoudsvormen —
         * dit is een kolomkop en geen zin, en die verbuigt niet mee.
         */
        'tally' => [
            'features' => 'onderdelen',
            'roles' => 'rollen om mee te beginnen',
            'triggers' => 'aanleidingen',
            'actions' => 'stappen',
        ],

        /*
         * Het schetsje naast de kop. Verzonnen namen, en dat mogen ze ook zijn:
         * dit is een tekening van het idee en geen schermafdruk.
         */
        'sketch' => [
            'channel' => 'klant-vandenberg',
            'guest' => '2 leden · 1 gast',
            'who' => 'Marieke',
            'time' => '09:14',
            'message' => 'De export loopt sinds vanmorgen vast op de nieuwe koppeling.',
            'ticket_label' => 'Ticket 124',
            'ticket_status' => 'Open',
            'ticket_title' => 'Export loopt vast',
            'ticket_assignee' => 'Opgepakt door Sam',
        ],

        /*
         * De drie kaarten boven de lijst. De namen en de omschrijvingen komen
         * uit de featureklassen — zie BuildFeatureInventory::spotlight() — en
         * alleen de zin eronder staat hier, want die is een oordeel: waarom dit
         * het onderdeel is waar iemand voor komt. De sleutels zijn de sleutels
         * van de onderdelen zelf, dus ze kunnen niet naar iets anders gaan
         * wijzen zonder dat het onderdeel hernoemd wordt.
         */
        'spotlight' => [
            'title' => 'Waar mensen voor blijven',
            'lead' => 'Drie dingen die er in een gewone chat niet in zitten, en waarvoor je anders drie andere abonnementen had.',
            'items' => [
                'tickets' => 'Het gesprek loopt door, het werk blijft staan. Een bericht wordt met één klik een ticket in de lijst van het kanaal, met een status en iemand die het oppakt — en de discussie eronder blijft waar hij was.',
                'transfers' => 'Zet bestanden klaar achter één link, ook voor iemand zonder account. Met een wachtwoord ervoor en een datum waarop de link niets meer opent, in plaats van een map die er over twee jaar nog staat.',
                'contracts' => 'Een PDF met invulvakken de deur uit, en getekend weer terug. Iedere ontvanger krijgt zijn eigen link per mail, jij ziet wie er al geweest is, en het ondertekende document komt met het audit-blad eronder in de workspace terug.',
            ],
        ],

        'features' => [
            'title' => 'Wat er in zit',
            'lead' => 'Elk onderdeel hieronder staat als klasse in de code, met deze naam en deze omschrijving. Wat er niet in staat, staat er niet.',
            // Naast de kop van een groep, zodat de lijst te overzien is voor je
            // hem leest. Het getal komt uit de inventaris, niet uit deze zin.
            'group_count' => '{1} :count onderdeel|[0,*] :count onderdelen',
        ],

        'channels' => [
            'title' => 'Een kanaal naar de vorm van het gesprek',
            'lead' => 'Een kanaal zet je niet aan, dus het staat niet in de lijst hierboven — terwijl je er de hele dag in zit. Dit zijn de knoppen eronder.',
            'layout' => 'Weergave',
            'posting' => 'Wie er post',
            'tickets' => 'Tickets',
            'documents' => 'Documenten',
        ],

        'workflow' => [
            'title' => 'Dingen die je workspace zelf doet',
            'lead' => 'Eén aanleiding, daarna een reeks stappen, met voorwaarden en splitsingen ertussen. Dit is waar je uit kiest.',
            // Als eenheid onder een getal, niet als kop boven een lijst.
            'when' => 'aanleidingen',
            'then' => 'stappen',
        ],

        'api' => [
            'title' => 'Voor je eigen script en je AI-client',
            'lead' => 'Een persoonlijk token voor je eigen script, OAuth voor een AI-client. Elke aanroep loopt langs dezelfde regels als het scherm: wat jij niet mag zien, geeft dit ook niet terug.',
            'endpoints' => 'De API',
            'reference' => 'De hele referentie →',
            'tools' => 'Wat een AI-client kan',
            'note' => 'Na jouw toestemming, in te trekken met één klik, en per workspace standaard uit.',
        ],

        'roles' => [
            'title' => 'Wie wat mag',
            'lead' => 'Vier rollen om mee te beginnen, en daarna maak je je eigen. Een rol is een naam met een setje rechten uit deze lijst — een gast is er één van, en ziet alleen de kanalen waarvoor hij is uitgenodigd.',
            'ability' => 'Recht',
            'browse' => 'De workspace zien',
            /*
             * Eén terzijde in plaats van drie. Wat eruit ging: de uitleg dat de
             * bovenste rij een eigenschap van de rol is en geen recht, en dat
             * niemand een recht kan uitdelen dat hij zelf niet heeft. Allebei
             * waar en allebei nog nauwkeurig uitgelegd waar het telt — in de
             * code en in het rollenscherm — maar geen van beide de reden dat
             * iemand op deze pagina is.
             */
            'note' => 'Dit is waar een workspace mee begint, niet waar het bij blijft: een beheerder hernoemt de rollen, vinkt rechten aan en uit, en maakt er zoveel bij als hij nodig heeft. De lijst met rechten ligt wél vast, want elk recht hier wordt ergens afgedwongen.',
            'yes' => 'ja',
            'no' => 'nee',
        ],
    ],

    'docs' => [
        'head' => 'API',
        'title' => 'De API',
        'intro' => 'Klein en met opzet klein gehouden. Elke aanroep loopt langs dezelfde regels als het scherm: wat jij niet mag zien, geeft dit ook niet terug, en een bericht dat hier binnenkomt gaat door dezelfde actie als een bericht dat je typt.',

        'wants' => 'Wat het wil',
        'returns' => 'Wat het teruggeeft',
        // Het getal komt uit de rate limiter, niet uit deze zin.
        'rate_limit' => '{1} Hoogstens :count per minuut|[0,*] Hoogstens :count per minuut',

        'auth' => [
            'title' => 'Aanmelden',
            'lead' => 'Een persoonlijk token hoort bij jou, niet bij een workspace. Je maakt er een bij Instellingen → API-tokens, en je ziet hem één keer.',
            'header' => 'De header',
            'note' => 'Elke mislukking geeft hetzelfde antwoord: 401, zonder te zeggen of het token niet bestaat, is ingetrokken of bij een verwijderd account hoort. Dat is met opzet — het verschil vertellen is iemand vertellen welke gok dichterbij was.',
        ],

        'token' => [
            'title' => 'Met je eigen token',
            'lead' => 'Alles hieronder gaat over de member wiens token je stuurt. Daarom staat er nergens een id in het pad: er is geen manier om bij iemand anders uit te komen.',
            'note' => 'Een workspace laat standaard niets met een token binnen. Zolang die schakelaar uit staat, geeft de kanalenlijst er niets uit terug en antwoordt posten er met 404 — hetzelfde antwoord als een kanaal dat niet bestaat, want het verschil zou verklappen wat er wél is.',
        ],

        'contracts' => [
            'title' => 'Contracten laten tekenen',
            'lead' => 'Iemand in de workspace maakt één keer een sjabloon: de PDF, de vakken, het aantal ontvangers, en zijn eigen handtekening eronder. Daarna gaat er een contract de deur uit op jouw aanroep, zonder dat er nog iemand een scherm opent. Hiervoor heb je een token nodig dat aan één workspace gekoppeld is én het recht "contracten" draagt — je maakt er een bij Instellingen → API-tokens.',
            'callback' => 'Wat er bij je binnenkomt',
            'verify' => 'De handtekening controleren',
            'note' => 'Er zijn drie gebeurtenissen: signed als iemand tekent, declined als iemand weigert, en completed als iedereen geweest is — die laatste pas als het ondertekende document klaarstaat, zodat je hem meteen kunt ophalen. Teken over de ruwe body, niet over een opnieuw gecodeerde versie: één spatie verschil en de vergelijking klopt niet meer. Een aflevering die mislukt wordt opnieuw geprobeerd; dat het bij jou misgaat houdt het tekenen nooit tegen.',
        ],

        'webhooks' => [
            'title' => 'Zonder token van een persoon',
            'lead' => 'Een webhook draagt zijn sleutel in het pad, want dat is wat de tools die erop wijzen verwachten. Hij komt dus in logs terecht — en dat is precies waarom hij in te trekken en opnieuw te maken is.',
        ],

        'ai' => [
            'title' => 'Voor een AI-client',
            'lead' => 'Een AI-client meldt zich met OAuth aan en vraagt jou om toestemming. Dit is wat hij daarna kan — dezelfde regels, dezelfde grenzen.',
            'tools' => 'De tools',
        ],
    ],

    /*
     * Wat elk endpoint doet, naast BuildApiReference — die houdt de vorm bij
     * (welke sleutels bestaan, wat ze aannemen, wat er terugkomt), dit is het
     * proza eromheen. De sleutel is de routenaam met punten als underscores.
     */
    'api' => [
        'api_v1_status_show' => [
            'summary' => 'De status van de member wiens token je stuurt.',
        ],
        'api_v1_status_update' => [
            'summary' => 'Zet je eigen status. Loopt langs dezelfde actie als het scherm, dus een statusregel die later aan de beurt is neemt het weer over.',
            'params' => [
                'availability' => ['rule' => 'verplicht', 'about' => 'available, away of do-not-disturb'],
                'emoji' => ['rule' => 'optioneel, max 16', 'about' => 'Eén emoji; meerdere code points tellen als één teken niet mee'],
                'text' => ['rule' => 'optioneel, max 100', 'about' => 'Wat je aan het doen bent'],
            ],
        ],
        'api_v1_channels_index' => [
            'summary' => 'De kanalen die dit token kan zien, om er een id uit te halen. Het chatscherm laat geen ids zien, dus zonder deze lijst is de volgende aanroep niet te doen. Hoogstens vijftig tegelijk.',
            'params' => [
                'search' => ['rule' => 'optioneel, query', 'about' => 'Filtert op naam, hoofdletterongevoelig'],
            ],
        ],
        'api_v1_messages_store' => [
            'summary' => 'Zeg iets in een kanaal. Hetzelfde bericht als vanaf het scherm: het draagt je naam, je kunt het bewerken en verwijderen, en niets markeert het als afkomstig van een script.',
            'params' => [
                'channel_id' => ['rule' => 'verplicht', 'about' => 'Uit GET /v1/channels'],
                'body' => ['rule' => 'verplicht, max 4000', 'about' => 'De tekst zelf; bijlagen kunnen hier niet'],
                'parent_id' => ['rule' => 'optioneel, ULID', 'about' => 'Antwoord in een bestaande thread in hetzelfde kanaal'],
            ],
        ],
        'api_v1_contract-templates_index' => [
            'summary' => 'De sjablonen die dit token mag versturen. Per sjabloon hoeveel ontvangers het verwacht, of de afzender er zelf al onder staat, en welke vakken je vooraf mag invullen. readyToSend is het veld om op te kijken: staat die op false, dan wordt versturen geweigerd.',
        ],
        'api_v1_contracts_store' => [
            'summary' => 'Stuur een sjabloon naar de mensen die moeten tekenen. Er wordt een nieuw contract van gemaakt — hetzelfde document, dezelfde vakken, en de handtekening die de afzender één keer onder het sjabloon zette — en iedere ontvanger krijgt zijn eigen tekenlink per mail. De afzender wordt niets meer gevraagd.',
            'params' => [
                'template_id' => ['rule' => 'verplicht, ULID', 'about' => 'Uit GET /v1/contract-templates'],
                'recipients' => ['rule' => 'verplicht, precies requiredSigners', 'about' => 'Lijst van {name, email}, in de volgorde waarin de vakken zijn getekend; optioneel values met veld-id → waarde'],
                'title' => ['rule' => 'optioneel, max 200', 'about' => 'Anders de titel van het sjabloon'],
                'message' => ['rule' => 'optioneel, max 2000', 'about' => 'Het zinnetje in de uitnodigingsmail; staat nooit op de PDF'],
                'locale' => ['rule' => 'optioneel, nl of en', 'about' => 'De taal van de uitnodiging, de herinnering en de ondertekende kopie. Anders de taal van het account achter het token'],
                'valid_for_days' => ['rule' => 'optioneel, 1–365', 'about' => 'Geteld vanaf nu; daarna opent de link niets meer'],
                'callback_url' => ['rule' => 'optioneel, https', 'about' => 'Hier wordt gemeld dat er getekend, geweigerd of afgerond is — alleen voor dit contract'],
                'callback_secret' => ['rule' => 'optioneel, min 16', 'about' => 'Waarmee X-Postduif-Signature wordt gezet. Laat je hem weg, dan krijg je er één terug in het antwoord — dat is de enige keer dat je hem ziet. Zonder callback_url heeft hij geen betekenis'],
            ],
        ],
        'api_v1_contracts_index' => [
            'summary' => 'Wat er loopt. Standaard zonder de concepten; geef status mee om iets anders te vragen.',
            'params' => [
                'status' => ['rule' => 'optioneel', 'about' => 'draft, sent, completed, cancelled of expired'],
            ],
        ],
        'api_v1_contracts_show' => [
            'summary' => 'Hoe ver één contract is: per ondertekenaar wanneer hij het opende, tekende of weigerde, en of het ondertekende document al klaarstaat. De tekenlinks staan hier bewust niet in — die gaan alleen naar de ontvanger zelf.',
        ],
        'api_v1_contracts_document' => [
            'summary' => 'De ondertekende PDF, met alle handtekeningen erop en het audit-blad erachter. Geeft 409 zolang er nog niets klaarstaat; signedCopy uit het contract vertelt of het nog komt of misging.',
        ],
        'webhooks_messages_store' => [
            'summary' => 'Een bericht posten zonder token van een persoon. De sleutel zit in het pad, want dat is wat de tools die hierop wijzen verwachten — en dat is ook waarom een webhook in te trekken en opnieuw te maken is.',
        ],
        'workflows_webhook' => [
            'summary' => 'Zet een workflow aan. Strakker begrensd dan een bericht-webhook: hierachter zit geen enkel bericht maar een rij stappen die in meerdere kanalen kan posten en mensen kan toevoegen.',
        ],
    ],

    /* Wat een crawler en een chatclient van deze pagina's te horen krijgen. */
    'seo' => [
        'home' => [
            'title' => 'Postduif — het gesprek en het werk op één plek',
            'description' => 'Kanalen en threads, tickets voor wat er blijft liggen, en bestanden die te groot zijn om mee te sturen. Klanten doen mee als gast en zien alleen hun eigen kanalen.',
        ],
        'docs' => [
            'title' => 'De API van Postduif',
            'description' => 'Elke aanroep loopt langs dezelfde regels als het scherm. Methodes, paden, parameters en de limieten per minuut — gelezen uit de router, niet overgetypt.',
        ],
    ],
];
