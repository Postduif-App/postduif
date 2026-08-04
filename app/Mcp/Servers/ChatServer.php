<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\FindChannelsTool;
use App\Mcp\Tools\SearchMessagesTool;
use App\Mcp\Tools\SendMessageTool;
use App\Mcp\Tools\SetStatusTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

/**
 * The chat, as an AI client sees it.
 *
 * Every tool runs as the member whose token was presented — see
 * AuthenticateApiToken — and asks the same policies the web application does.
 * That is the whole design: this server adds a way in, never a way around. A
 * private channel the member cannot open in the browser does not exist here
 * either.
 */
#[Name('Pcom chat')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Deze server geeft toegang tot de chat van één gebruiker: de kanalen die zij
mogen zien, de berichten daarin, het plaatsen van een bericht, en het zetten
van hun status.

Werkwijze: zoek eerst het kanaal met find-channels, gebruik daarna het id dat
je terugkrijgt. Kanaal-ids zijn niet te raden en verschillen per workspace.

Wat je niet ziet, mag deze gebruiker niet zien. Een leeg antwoord betekent
"niet gevonden of geen toegang", en die twee zijn met opzet niet te
onderscheiden.
TEXT)]
class ChatServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        FindChannelsTool::class,
        SearchMessagesTool::class,
        SendMessageTool::class,
        SetStatusTool::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
