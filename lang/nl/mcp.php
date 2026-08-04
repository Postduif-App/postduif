<?php

/*
 * Wat een MCP-tool teruggeeft aan wie hem aanriep.
 *
 * Alleen de antwoorden, niet de beschrijvingen boven de tools: die staan in een
 * PHP-attribuut, worden één keer per sessie opgehaald en door de client bewaard.
 * Een beschrijving die per taal verschilt zou blijven hangen op de taal die
 * toevallig actief was bij het eerste ophalen.
 *
 * Deze zinnen komen wél elke aanroep opnieuw langs, en belanden via het model
 * bij de lezer.
 */
return [
    /*
     * De weigering van de poortwachter zelf, vóór er ooit een tool aan te pas
     * komt. Hij bewaakt zowel de MCP-server als de API, en staat hier omdat een
     * client die hem leest hier komt kijken.
     */
    'token' => [
        'invalid' => 'Ongeldig of ontbrekend MCP-token.',
    ],

    'channels' => [
        'none' => 'Deze gebruiker zit in geen enkel kanaal.',
        'no_match' => 'Geen kanaal gevonden voor ":search".',
    ],

    'send' => [
        'empty' => 'Een leeg bericht is geen bericht.',
        // Eén antwoord voor "bestaat niet" en "niet van jou"; zie de toelichting
        // in SendMessageTool waarom die twee hetzelfde moeten klinken.
        'no_channel' => 'Kanaal niet gevonden.',
        'not_allowed' => 'Deze gebruiker mag niet posten in dit kanaal.',
        'admins_only' => 'Alleen beheerders plaatsen hier berichten.',
        'not_a_member' => 'Ze zijn nog geen lid van dit kanaal.',
    ],

    'search' => [
        'empty' => 'Geef iets om op te zoeken.',
        'no_results' => 'Niets gevonden voor ":terms".',
    ],

    'status' => [
        'closed' => 'AI-toegang staat niet aan in een workspace van deze gebruiker.',
    ],
];
