<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every channel this workspace has, in one table.
 *
 * The sidebar answers "where do I go now" and deliberately leaves things out:
 * archived channels, channels somebody else made and never invited you to, and
 * everything that is true about a channel but not worth a row's width. This
 * screen is the other question — "what does this workspace actually consist of"
 * — and it is the only place that answers it without opening the admin panel,
 * which belongs to whoever runs the platform rather than to whoever runs the
 * workspace.
 *
 * Direct messages stay out. They are conversations between two people rather
 * than places the workspace made, and listing them here would turn a management
 * screen into a directory of who talks to whom.
 */
class WorkspaceChannelController extends Controller
{
    use ResolvesCurrentWorkspace;

    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        $viewer = $request->user();

        return Inertia::render('settings/workspace-channels', [
            'workspaceName' => $workspace->name,
            'workspaceSlug' => $workspace->slug,
            'channels' => $workspace->channels()
                ->where('type', '!=', ChannelType::Direct)
                /*
                 * Archived ones included, which is half the point of this
                 * screen: they are invisible everywhere else, and "waar is dat
                 * kanaal van vorig jaar gebleven" has no other answer.
                 */
                ->with(['creator:id,name', 'tags:id,name'])
                // One query for every count rather than one per channel per
                // count, which on a workspace with fifty channels is the
                // difference between a page and a stall.
                ->withCount(['members', 'messages', 'tickets', 'links', 'webhooks'])
                ->orderBy('name')
                ->get()
                ->map(fn (Channel $channel): array => [
                    'id' => $channel->id,
                    'name' => (string) $channel->name,
                    'topic' => $channel->topic,
                    'type' => $channel->type->value,
                    'layout' => $channel->layout->value,
                    'postingPolicy' => $channel->posting_policy->value,
                    'ticketPolicy' => $channel->ticket_policy->value,
                    'memberCount' => $channel->members_count,
                    'messageCount' => $channel->messages_count,
                    'ticketCount' => $channel->tickets_count,
                    'linkCount' => $channel->links_count,
                    'webhookCount' => $channel->webhooks_count,
                    'tags' => $channel->tags->pluck('name')->all(),
                    // Null where the person who made it has since left, which
                    // the column allows on purpose — see the migration.
                    'createdBy' => $channel->creator?->name,
                    'createdAt' => $channel->created_at?->toIso8601String(),
                    'lastMessageAt' => $channel->last_message_at?->toIso8601String(),
                    'archivedAt' => $channel->archived_at?->toIso8601String(),
                    /*
                     * Asked per channel rather than once for the page: a
                     * beheerder may manage every channel, but the creator of
                     * one may manage theirs alone — and this list holds both
                     * kinds at once. See ChannelPolicy.
                     */
                    'canArchive' => $viewer->can('archiveChannel', $channel),
                    'canOpen' => $viewer->can('view', $channel),
                ])->all(),
        ]);
    }
}
