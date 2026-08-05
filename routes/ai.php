<?php

use App\Mcp\Servers\ChatServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * Discovery and registration, as the protocol has it.
 *
 * Three things a client needs before it can ask for anything: where the
 * authorisation server is (/.well-known/oauth-protected-resource), what that
 * server supports (/.well-known/oauth-authorization-server), and somewhere to
 * register itself (POST /oauth/register). The last one is what makes this work
 * with a hosted client nobody here has ever configured — it arrives, registers,
 * and asks the member for consent.
 */
Mcp::oauthRoutes();

/*
 * The chat as an AI client sees it.
 *
 * Behind OAuth rather than a token somebody pastes into a config file: that is
 * what the protocol specifies and what the hosted clients support, and it means
 * the grant is something a member gave on a screen and can take back.
 *
 * Whichever way in, the request runs as one member and every tool asks the same
 * policies the web application does. Throttled as well: a client that loops is
 * a client that would otherwise write a channel full of messages before
 * anybody noticed.
 */
Mcp::web('/mcp/chat', ChatServer::class)
    ->middleware(['auth:api', 'throttle:60,1']);
