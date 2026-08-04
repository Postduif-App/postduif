<?php

namespace App\Mcp\Tools;

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
 * The channels this member can open, so a client has an id to work with.
 *
 * Scoped through Channel::scopeVisibleTo — the same scope the sidebar uses,
 * not a query of its own. Two lists that ought to agree drift, and here that
 * would mean an AI client seeing a private channel the browser hides.
 */
#[Description('Zoek de kanalen die deze gebruiker kan zien. Gebruik dit eerst: de andere tools werken met het id dat je hier terugkrijgt.')]
class FindChannelsTool extends Tool
{
    /** Enough to choose from without turning an answer into a directory. */
    private const LIMIT = 50;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $search = trim((string) $request->get('search', ''));

        $open = $user->workspacesOpenToAi()->pluck('id');

        $channels = Channel::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->with(['workspace', 'members'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            /*
             * Only the workspaces this member belongs to that let AI clients
             * in at all. Membership is already implied by visibleTo, but saying
             * it here means a workspace they were removed from cannot surface
             * through a stale membership row.
             */
            ->filter(fn (Channel $channel): bool => $channel->workspace instanceof Workspace
                && $open->contains($channel->workspace->id));

        if ($channels->isEmpty()) {
            return Response::text($search === ''
                // Says nothing about why. A workspace that switched AI access
                // off has not told this client that it exists.
                ? __('mcp.channels.none')
                : __('mcp.channels.no_match', ['search' => $search]));
        }

        return Response::json([
            'channels' => $channels->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'label' => $channel->displayNameFor($user),
                'type' => $channel->type->value,
                'topic' => $channel->topic,
                'workspace' => $channel->workspace->name,
                // Reading a public channel is open; writing means having
                // joined. A client that knows this up front does not have to
                // discover it by being refused.
                'isMember' => $channel->members->contains($user),
                'canPost' => $user->can('post', $channel),
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Deel van een kanaalnaam. Laat leeg voor alle kanalen die deze gebruiker kan zien.'),
        ];
    }
}
