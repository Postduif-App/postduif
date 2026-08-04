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
