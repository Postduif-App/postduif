<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Managing a channel's incoming webhooks from the channel itself.
 *
 * JSON rather than Inertia redirects, following the member picker next door.
 * That is not only convention here: creating a webhook answers with a token
 * that exists exactly once, and handing it back in the response keeps it out of
 * the session — where a flashed value would outlive the moment it was meant
 * for, and travel along with the next page render.
 */
class ChannelWebhookController extends Controller
{
    public function index(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $this->authorizeChannel($request, $workspace, $channel);

        return response()->json([
            'webhooks' => $channel->webhooks()
                ->with('creator')
                ->orderByDesc('id')
                ->get()
                ->map($this->present(...))
                ->values(),
        ]);
    }

    public function store(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $this->authorizeChannel($request, $workspace, $channel);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'bot_name' => ['required', 'string', 'max:80'],
        ]);

        $webhook = new Webhook([
            'workspace_id' => $channel->workspace_id,
            'channel_id' => $channel->id,
            'name' => $validated['name'],
            'bot_name' => $validated['bot_name'],
            'created_by' => $request->user()->id,
        ]);

        $token = $webhook->regenerateToken();
        $webhook->save();

        // The only response that ever carries the plain token. Everything after
        // this reads the webhook back from the database, where only the hash is.
        return response()->json([
            'webhook' => $this->present($webhook->load('creator')),
            'token' => $token,
            'url' => route('webhooks.messages.store', $token),
        ], 201);
    }

    /**
     * Hand out a new token, invalidating the old one.
     *
     * The way back when a URL leaked, and the only way to get a working one for
     * a webhook created before tokens were kept. Deliberately not folded into
     * an edit: it breaks whatever is currently posting, so it has to be asked
     * for on its own.
     */
    public function regenerate(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Webhook $webhook,
    ): JsonResponse {
        $this->authorizeChannel($request, $workspace, $channel);
        abort_unless($webhook->channel_id === $channel->id, 404);

        $webhook->regenerateToken();
        $webhook->save();

        return response()->json(['webhook' => $this->present($webhook->load('creator'))]);
    }

    /**
     * Revoke, rather than delete: the messages this webhook posted stay, and so
     * does the record of what was once pointed at this channel.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Webhook $webhook,
    ): JsonResponse {
        $this->authorizeChannel($request, $workspace, $channel);

        $webhook->forceFill(['revoked_at' => now()])->save();

        return response()->json(['webhook' => $this->present($webhook->load('creator'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'botName' => $webhook->bot_name,
            'createdBy' => $webhook->creator?->name,
            'lastUsedAt' => $webhook->last_used_at?->toIso8601String(),
            'revokedAt' => $webhook->revoked_at?->toIso8601String(),
            // Whoever may manage the channel may see the URL of the webhooks
            // pointed at it — they are the ones who set them up, and the same
            // ability already lets them create and revoke one. Null for a
            // revoked webhook, and for the ones made before the token was kept.
            'url' => $webhook->url(),
        ];
    }

    /**
     * Whoever may change the channel's settings may manage its webhooks. They
     * are a setting: something the channel does, configured by the person who
     * runs it — and it keeps the right to create one in the same hands as the
     * right to switch it off.
     */
    private function authorizeChannel(Request $request, Workspace $workspace, Channel $channel): void
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($workspace->hasMember($request->user()), 403);
        $this->authorize('manageSettings', $channel);
    }
}
