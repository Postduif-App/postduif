<?php

namespace App\Http\Controllers;

use App\Actions\Mail\ReceiveInboundEmail;
use App\Models\WorkspaceMailSettings;
use App\Support\InboundEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a mail provider posts an e-mail somebody sent to the workspace.
 *
 * The only endpoint in this application with no session, no member and no
 * policy behind it. What stands in their place is the token in the path: it is
 * long, it is random, it is given to nobody but the provider, and rolling it is
 * one button on the settings screen. That is a smaller mechanism than a
 * per-provider signature check, and small is the point — every provider signs
 * differently, and a scheme that has to be right four ways is one that will be
 * subtly wrong once.
 *
 * The endpoint is deliberately incurious about which provider is calling it.
 * See InboundEmail, which reads whatever shape arrives.
 */
class InboundMailController extends Controller
{
    public function __construct(private readonly ReceiveInboundEmail $receive) {}

    /**
     * Always 200, unless the token is wrong.
     *
     * A provider reads anything else as "try again", and there is nothing worth
     * retrying here: a mail for a workspace that has since switched tickets off
     * will be just as unwanted in four hours, and the retries in between are a
     * mailbox filling up at their end. An unknown token is the one real error,
     * and 404 is the honest answer — somebody is posting to an address that
     * does not exist.
     */
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $settings = WorkspaceMailSettings::query()
            ->with(['workspace', 'inboundChannel'])
            ->where('inbound_token', $token)
            ->first();

        abort_if($settings === null, 404);

        $result = $this->receive->handle($settings, InboundEmail::fromPayload($request->all()));

        /*
         * What happened to it, for the provider's own delivery log. Not for
         * anybody else: this response is read by a machine that files it and
         * moves on, and putting a ticket number in it is the difference between
         * a log that can be searched and one that only says "ok".
         */
        return response()->json([
            'handled' => $result !== null,
        ]);
    }
}
