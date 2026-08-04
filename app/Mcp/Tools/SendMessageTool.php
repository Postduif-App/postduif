<?php

namespace App\Mcp\Tools;

use App\Actions\Chat\SendMessage;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * Say something in a channel, as the member whose token this is.
 *
 * Through the same SendMessage action the web application uses, so the message
 * gets its mentions, its read state, its counters and its broadcast. A plain
 * insert here would produce a message that appears in nobody's sidebar and
 * pings nobody — technically stored, practically unsaid.
 *
 * The message is ordinary in every way: it carries the member's name, they can
 * edit and delete it, and nothing marks it as having come from a machine. That
 * is deliberate — they asked for it to be sent, and a badge would be the
 * application second-guessing its own member.
 */
#[Description('Plaats een bericht in een kanaal, namens deze gebruiker. Gebruik find-channels om het kanaal-id te vinden.')]
class SendMessageTool extends Tool
{
    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $body = trim((string) $request->get('body', ''));

        if ($body === '') {
            return Response::error(__('mcp.send.empty'));
        }

        $channel = Channel::query()->whereKey((int) $request->get('channel_id'))->first();

        /*
         * One answer for "no such channel" and "not yours": telling them apart
         * would let a client probe for which ids exist.
         */
        if ($channel === null || ! $user->can('view', $channel)) {
            return Response::error(__('mcp.send.no_channel'));
        }

        /*
         * And the same answer again where the workspace does not let AI clients
         * in. A different one — "AI-toegang staat uit" — would confirm that the
         * channel exists, which is exactly what the line above refuses to do.
         */
        if (! $user->workspacesOpenToAi()->contains('id', $channel->workspace_id)) {
            return Response::error(__('mcp.send.no_channel'));
        }

        if (! $user->can('post', $channel)) {
            return Response::error(
                __('mcp.send.not_allowed')
                .' '.($channel->members()->whereKey($user->id)->exists()
                    ? __('mcp.send.admins_only')
                    : __('mcp.send.not_a_member'))
            );
        }

        $message = $this->sendMessage->handle(
            channel: $channel,
            author: $user,
            body: $body,
        );

        return Response::json([
            'sent' => true,
            'messageId' => $message->id,
            'channel' => $channel->name,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel_id' => $schema->integer()
                ->description('Het kanaal waarin het bericht komt.')
                ->required(),
            'body' => $schema->string()
                ->description('De tekst van het bericht. Ondersteunt @vermeldingen en #kanaalverwijzingen, net als in de app.')
                ->required(),
        ];
    }
}
