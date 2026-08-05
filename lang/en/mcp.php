<?php

/*
 * What an MCP tool answers whoever called it.
 *
 * The answers only, not the descriptions above the tools: those sit in a PHP
 * attribute, are fetched once per session and kept by the client. A description
 * that differed per language would stick on whichever language happened to be
 * active at the first fetch.
 *
 * These lines come past on every call, and reach the reader through the model.
 */
return [
    /*
     * The gatekeeper's own refusal, before any tool is reached at all. It
     * guards the MCP server and the API alike, and sits here because a client
     * reading it comes looking here.
     */
    'token' => [
        'invalid' => 'Invalid or missing MCP token.',
    ],

    /*
     * The screen an AI client sends somebody to for consent. The client has put
     * its own name in the address bar, so this is the only place saying whose
     * account is about to be handed over — which is why the address is stated
     * rather than assumed.
     */
    'authorize' => [
        'title' => 'Give :client access',
        'heading' => ':client wants to join in on your behalf',
        'explanation' => 'This client gets access to Postduif as though it were you. Only approve it if you started this yourself just now.',
        'as' => 'As',
        'scope' => 'Reading and writing in the channels you can see, and setting your status.',
        'limits' => 'Never more than you may do yourself. A private channel you are not in does not exist for this client either.',
        'approve' => 'Allow',
        'deny' => 'Refuse',
        'revoke_hint' => 'You can withdraw this later under Settings → API tokens.',
    ],

    'channels' => [
        'none' => 'This user is not in any channel.',
        'no_match' => 'No channel found for ":search".',
    ],

    'send' => [
        'empty' => 'An empty message is not a message.',
        'no_channel' => 'Channel not found.',
        'not_allowed' => 'This user may not post in this channel.',
        'admins_only' => 'Only admins post here.',
        'not_a_member' => 'They are not a member of this channel yet.',
    ],

    'search' => [
        'empty' => 'Give me something to search for.',
        'no_results' => 'Nothing found for ":terms".',
    ],

    'status' => [
        'closed' => 'AI access is not switched on in any workspace of this user.',
    ],
];
