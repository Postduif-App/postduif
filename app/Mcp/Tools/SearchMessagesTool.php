<?php

namespace App\Mcp\Tools;

use App\Actions\Chat\SearchMessages;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * Search the messages this member may read.
 *
 * Runs through the same action the web application's search box uses, which is
 * the point: the channel scope and — more importantly — the stripping of
 * blocked words out of the search term are stated once. A second query here
 * would be a second place to forget them, and forgetting the second one makes
 * the blocklist one AI client away from useless.
 */
#[Description('Doorzoek de berichten die deze gebruiker mag lezen. Geef optioneel een kanaal-id mee om binnen één kanaal te zoeken; gebruik find-channels om dat id te vinden.')]
class SearchMessagesTool extends Tool
{
    private const LIMIT = 25;

    public function __construct(private readonly SearchMessages $searchMessages) {}

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $terms = trim((string) $request->get('query', ''));

        if ($terms === '') {
            return Response::error('Geef iets om op te zoeken.');
        }

        $channel = $this->channel($request, $user);
        $results = $this->search($user, $terms, $channel);

        if ($results === []) {
            return Response::text('Niets gevonden voor "'.$terms.'".');
        }

        return Response::json(['results' => $results]);
    }

    /**
     * Search every workspace this member belongs to.
     *
     * The web application searches one workspace because it is always looking
     * at one; a client has no such context and would otherwise have to ask
     * which workspace before it could ask anything else.
     *
     * @return array<int, array<string, mixed>>
     */
    private function search(User $user, string $terms, ?Channel $channel): array
    {
        $open = $user->workspacesOpenToAi();

        $workspaces = $channel !== null
            ? [$channel->workspace]
            : $open;

        $results = [];

        foreach ($workspaces as $workspace) {
            // The narrowed case comes in from the outside, so it is checked
            // against the same list rather than trusted for having been named.
            if (! $workspace instanceof Workspace || ! $open->contains('id', $workspace->id)) {
                continue;
            }

            foreach ($this->searchMessages->handle($workspace, $user, $terms, $channel, self::LIMIT) as $hit) {
                $results[] = [...$hit, 'workspace' => $workspace->name];
            }
        }

        return array_slice($results, 0, self::LIMIT);
    }

    /**
     * The channel to narrow to, or null.
     *
     * A channel this member cannot read comes back as null rather than as an
     * error: "not found" and "not allowed" are deliberately the same answer,
     * so an id cannot be probed for existence.
     */
    private function channel(Request $request, User $user): ?Channel
    {
        $channelId = $request->get('channel_id');

        if ($channelId === null) {
            return null;
        }

        $channel = Channel::query()->whereKey((int) $channelId)->first();

        return $channel !== null && $user->can('view', $channel) ? $channel : null;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Waarop gezocht wordt.')
                ->required(),
            'channel_id' => $schema->integer()
                ->description('Beperk tot één kanaal. Laat weg om overal te zoeken waar deze gebruiker bij kan.'),
        ];
    }
}
