<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Waar een verbinding vandaan komt
    |--------------------------------------------------------------------------
    |
    | Two browsers on the same network find each other by themselves. Anywhere
    | else they need help: a STUN server to discover what their address looks
    | like from outside, and — behind the sort of NAT most company networks and
    | some mobile providers use — a TURN server that relays the audio, because
    | no direct path exists to find.
    |
    | Both are deployment facts rather than product ones, which is why they live
    | in env and are handed to the browser per request instead of being baked
    | into the bundle at build time.
    |
    */

    'stun_urls' => array_values(array_filter(
        explode(',', (string) env('HUDDLE_STUN_URLS', '')),
    )),

    'turn_urls' => array_values(array_filter(
        explode(',', (string) env('HUDDLE_TURN_URLS', '')),
    )),

    /*
    |--------------------------------------------------------------------------
    | Het gedeelde geheim van de relay
    |--------------------------------------------------------------------------
    |
    | coturn's REST scheme: rather than handing every member a fixed username
    | and password — which is a relay anybody who opens the page can use for
    | anything, for as long as they like — the server signs a name that expires.
    | The secret itself never leaves the application.
    |
    | Empty means the relay wants a plain username and password instead, or that
    | there is no relay at all.
    |
    */

    'turn_secret' => env('HUDDLE_TURN_SECRET'),

    /** How long a signed credential stays good. Long enough for a conversation. */
    'turn_ttl_minutes' => (int) env('HUDDLE_TURN_TTL_MINUTES', 120),

];
