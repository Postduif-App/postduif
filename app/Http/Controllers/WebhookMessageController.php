<?php

namespace App\Http\Controllers;

use App\Actions\Chat\ResolveWebhookBody;
use App\Actions\Chat\SendMessage;
use App\Features\Webhooks;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookMessageController extends Controller
{
    /**
     * Post into a channel on behalf of an incoming webhook.
     *
     * Stateless on purpose: no session, no CSRF, no signed-in member. The token
     * in the URL is the entire credential, which is why it can be revoked and
     * why nothing here leaks whether a given token was ever valid.
     */
    public function __invoke(
        Request $request,
        string $token,
        SendMessage $sendMessage,
        ResolveWebhookBody $resolveBody,
    ): JsonResponse {
        $webhook = Webhook::query()
            ->active()
            ->with('channel.workspace')
            ->where('token_hash', Webhook::hashToken($token))
            ->first();

        // 404 rather than 401, and the same answer for unknown as for revoked:
        // a caller holding a dead token learns nothing about whether it was
        // ever alive.
        abort_if($webhook === null, 404, 'Onbekende webhook.');

        abort_if(
            $webhook->channel === null || $webhook->channel->archived_at !== null,
            422,
            'Dit kanaal is gearchiveerd.',
        );

        /*
         * The workspace may have switched webhooks off since this one was made.
         * The same 404 as an unknown token, for the same reason: a different
         * answer here would confirm that the token is real.
         */
        abort_unless(
            $webhook->channel->workspace?->hasFeature(Webhooks::class) ?? false,
            404,
            'Onbekende webhook.',
        );

        // ChannelPostingPolicy is deliberately not consulted. It narrows who
        // among the members may post, and a webhook is not a member — it was
        // put here by somebody who could already manage the channel, and that
        // is where the decision was made.
        /*
         * What counts as the message is the webhook's own business — see
         * ResolveWebhookBody. Resolved after the checks above rather than in a
         * form request, so an unknown token never gets as far as being told
         * what shape its payload should have been.
         */
        $message = $sendMessage->fromWebhook(
            $webhook,
            $resolveBody->handle($webhook, $request->all()),
        );

        $webhook->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'id' => $message->id,
            'channel_id' => $message->channel_id,
        ], 201);
    }
}
