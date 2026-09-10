<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBacklogWebhookJob;
use App\Models\BacklogConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Backlog posts what happened to an issue.
 *
 * Deliberately thin, the same shape as WorkflowWebhookController: verify, hand
 * off to a queued job, answer. The one thing this endpoint owns that a queued
 * job cannot is the signature — checking it here, before anything is written
 * anywhere, is what makes 401 mean "this was not Backlog" rather than "this was
 * Backlog and something downstream did not like it".
 */
class BacklogWebhookController extends Controller
{
    /**
     * How old a delivery's timestamp may be before it is refused.
     *
     * Five minutes is generous for network delay and stingy for a replay: a
     * signed body captured off the wire is only ever useful to someone within
     * this window, after which it is just a fact Backlog already told us once.
     */
    private const REPLAY_WINDOW_SECONDS = 300;

    public function __invoke(Request $request, BacklogConnection $connection): JsonResponse
    {
        $rejection = $this->rejectionReason($request, $connection);

        if ($rejection !== null) {
            /*
             * Which check failed, never the secret or the signature itself —
             * this is the one line that tells a beheerder "the timestamp was
             * six minutes old" instead of them staring at a bare 401 with no
             * way to tell a clock-skew problem from a pasted-wrong secret
             * without SSHing in and reading the source.
             */
            Log::warning('Backlog webhook delivery rejected', [
                'connection_id' => $connection->id,
                'reason' => $rejection,
            ]);

            abort(401);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        ProcessBacklogWebhookJob::dispatch(
            $connection->id,
            (string) $request->header('X-Backlog-Event'),
            $payload,
        );

        /*
         * 202, not 200 — the same reason WorkflowWebhookController answers
         * this way: nothing here waited for the job, and "accepted" is a
         * different claim from "applied".
         */
        return response()->json(['accepted' => true], 202);
    }

    /**
     * Why this request does not carry a signature the connection's own
     * secret could have produced over exactly these bytes, recently — or
     * null when it does.
     *
     * Checked against the raw body rather than a re-encoded one, for the same
     * reason DeliverContractWebhookJob signs a string instead of an array: any
     * re-encoding that orders a key differently produces a body whose HMAC no
     * longer matches what Backlog actually sent.
     */
    private function rejectionReason(Request $request, BacklogConnection $connection): ?string
    {
        $signatureHeader = (string) $request->header('X-Backlog-Signature');
        $timestamp = (string) $request->header('X-Backlog-Delivery-Timestamp');
        $secret = $connection->webhook_secret;

        if ($secret === null) {
            return 'connection has no webhook secret configured';
        }

        if ($signatureHeader === '') {
            return 'X-Backlog-Signature header missing';
        }

        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return 'X-Backlog-Delivery-Timestamp header missing or not numeric';
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return 'X-Backlog-Signature header is not in the sha256=... shape';
        }

        $provided = substr($signatureHeader, strlen('sha256='));
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $provided)) {
            return 'signature does not match this connection\'s webhook secret';
        }

        $skew = abs(time() - (int) $timestamp);

        if ($skew > self::REPLAY_WINDOW_SECONDS) {
            return "timestamp is {$skew}s old, outside the ".self::REPLAY_WINDOW_SECONDS.'s replay window';
        }

        return null;
    }
}
