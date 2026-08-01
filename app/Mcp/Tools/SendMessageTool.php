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
            return Response::error('Een leeg bericht is geen bericht.');
        }

        $channel = Channel::query()->whereKey((int) $request->get('channel_id'))->first();

        /*
         * One answer for "no such channel" and "not yours": telling them apart
         * would let a client probe for which ids exist.
         */
        if ($channel === null || ! $user->can('view', $channel)) {
            return Response::error('Kanaal niet gevonden.');
        }

        if (! $user->can('post', $channel)) {
            return Response::error(
                'Deze gebruiker mag niet posten in dit kanaal.'
                .($channel->members()->whereKey($user->id)->exists()
                    ? ' Alleen beheerders plaatsen hier berichten.'
                    : ' Ze zijn nog geen lid van dit kanaal.')
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
