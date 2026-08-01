<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\ChatServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * The chat as an AI client sees it.
 *
 * Behind a token that signs the request in as one member, so every tool runs
 * against the same policies the web application uses. Throttled as well: a
 * client that loops is a client that would otherwise write a channel full of
 * messages before anybody noticed.
 */
Mcp::web('/mcp/chat', ChatServer::class)
    ->middleware([AuthenticateMcpToken::class, 'throttle:60,1']);
