<?php

namespace App\Jobs;

use App\Http\Controllers\ContractDocumentController;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\ContractWebhook;
use App\Workflows\GuardOutboundUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Tell one address about one thing that happened to one contract.
 *
 * One job per delivery rather than one per event, and that is the whole design.
 * A workspace with three subscriptions posts three times, and a receiving system
 * that is down must not cost the other two their news — nor, far more
 * importantly, cost anybody their signature. Everything in here happens after
 * the contract is safe in the database; the worst a total failure of this job
 * can do is leave a row in contract_webhooks saying so.
 *
 * What travels in the payload is ids and a timestamp, never the subscription's
 * secret. A queue payload is JSON in Redis that any operator can read and that
 * a failed_jobs row keeps for weeks — so the address and the secret are looked
 * up at the moment of sending, from a row that may since have been switched off
 * or rotated. That also gives the right behaviour for free: withdrawing a
 * subscription stops the deliveries already queued for it.
 */
class DeliverContractWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * Four goes, spread over about six minutes.
     *
     * Sized against what actually goes wrong at the far end: a deploy, a
     * restart, a rate limit. Those are over in seconds to minutes, and nothing
     * that is still broken after five minutes is going to be fixed by asking a
     * fifth time — it wants a person to look at the row and see the status
     * sitting there.
     */
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * How long we are willing to hold a worker for somebody else's server.
     *
     * Short, and shorter than the workflow step's ten: that one is a person
     * waiting to see what an API said, this is a notification nobody is
     * watching. A receiver that needs more than five seconds to acknowledge a
     * webhook is doing its work in the request instead of queueing it, which is
     * their bug to fix and not ours to wait for.
     */
    private const TIMEOUT = 5;

    private const CONNECT_TIMEOUT = 3;

    /**
     * How long the link to the signed document stays good.
     *
     * Long enough that an integration which was down for a weekend can still
     * fetch what it was told about, short enough that a payload sitting in
     * somebody's log file is not a permanent key to the document. See
     * documentUrl() for why it is a signed link at all.
     */
    private const DOCUMENT_LIFETIME_DAYS = 7;

    /**
     * @param  string  $event  One of ContractWebhook::EVENTS.
     * @param  int|null  $webhookId  The subscription to deliver to, or null for
     *                               the contract's own callback address.
     * @param  string  $occurredAt  When the thing happened, not when this ran —
     *                              taken at dispatch so that a delivery which
     *                              spent five minutes in retries does not claim
     *                              the contract was signed five minutes late.
     */
    public function __construct(
        public readonly string $event,
        public readonly string $contractId,
        public readonly ?int $webhookId,
        public readonly string $occurredAt,
    ) {
        /*
         * Its own queue, for the reason the link previews have one: this talks
         * to the open internet, and a receiver that answers slowly must never
         * be able to hold up the notification somebody is actually waiting for.
         */
        $this->onQueue('webhooks');
    }

    public function handle(GuardOutboundUrl $guard): void
    {
        $contract = Contract::query()->with('signers')->find($this->contractId);

        if ($contract === null) {
            // Deleted between the dispatch and the run. There is nothing left
            // to describe, and nothing wrong either.
            return;
        }

        $webhook = $this->webhookId === null
            ? null
            : ContractWebhook::query()->find($this->webhookId);

        /*
         * Withdrawn or switched off while this sat in the queue. Silence is the
         * right answer: somebody turned this address off precisely so that it
         * would stop hearing from us, and honouring a delivery that was already
         * in flight would make "uit" mean "uit, over een paar minuten".
         */
        if ($this->webhookId !== null && ($webhook === null || $webhook->isDisabled())) {
            return;
        }

        $url = $webhook->url ?? $contract->callback_url;
        $secret = $webhook->secret ?? $contract->callback_secret;

        if ($url === null || $secret === null) {
            return;
        }

        try {
            /*
             * Checked again here, not only when it was saved.
             *
             * The screen checks so that somebody finds out immediately, but a
             * name resolves afresh every time it is used: an address that
             * answered with a public IP when it was entered can answer with
             * 169.254.169.254 today. This is the check that is actually load
             * bearing.
             */
            $url = $guard->handle($url);
        } catch (RuntimeException $exception) {
            /*
             * Failed rather than retried. A private address will still be
             * private in five minutes, and three more attempts would only be
             * three more chances to knock on the inside of a network.
             */
            $this->record($webhook, status: null);
            $this->fail($exception);

            return;
        }

        $body = $this->body($contract);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Postduif-Event' => $this->event,

                /*
                 * The signature, over exactly the bytes below and nothing else.
                 *
                 * Which is why the body is built as a string and sent as a
                 * string: handing the client an array would have it encode the
                 * payload a second time, and an encoder that orders a key
                 * differently or escapes a slash where we did not produces a
                 * body the far end cannot verify. The receiver checks this by
                 * running the same hash over the raw request body, so the two
                 * have to be the same bytes down to the whitespace.
                 *
                 * Prefixed with the algorithm the way GitHub and Stripe write
                 * it: it costs four characters and it means the day this moves
                 * to something else, a receiver can tell which it is holding.
                 */
                'X-Postduif-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ])
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                /*
                 * A redirect is a second address, chosen by the thing we are
                 * posting to. Everything the guard decided about the first would
                 * have to be decided again about that one — see HttpRequest,
                 * which refuses them for the same reason.
                 */
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            // Nothing answered at all, so there is no status to record — only
            // the fact and the moment. Thrown on, so the backoff gets its turn.
            $this->record($webhook, status: null);

            throw $exception;
        }

        $this->record($webhook, $response->status());

        if (! $response->successful()) {
            /*
             * Anything but a 2xx is a delivery that did not happen. Thrown so
             * the retries apply, because the overwhelming majority of these are
             * a receiver having a bad minute rather than a payload it will
             * never accept.
             */
            throw new RuntimeException(
                "Webhook {$this->event} refused by {$url} with status {$response->status()}."
            );
        }
    }

    /**
     * After the last attempt, say so on the row.
     *
     * A backstop rather than the only place this is written: every failing
     * attempt already stamps the row, so the screen shows something the moment
     * things start going wrong rather than six minutes later. What this adds is
     * the failure modes that never reach record() at all — a payload that could
     * not be built, a worker killed mid-send.
     *
     * Only ever a subscription. A contract's own callback address has no row to
     * mark: it belongs to one delivery for one system, which is watching for
     * the payload rather than for our opinion of its uptime.
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->webhookId === null) {
            return;
        }

        ContractWebhook::query()
            ->whereKey($this->webhookId)
            ->update(['last_failed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Write down how this attempt went.
     *
     * Both timestamps are kept rather than one column with a meaning that
     * flips, because the two questions a beheerder asks are different: "heeft
     * dit adres het ooit ontvangen" and "is er iets kapot". A subscription that
     * delivered this morning and failed twice since should say both.
     */
    private function record(?ContractWebhook $webhook, ?int $status): void
    {
        if ($webhook === null) {
            return;
        }

        $succeeded = $status !== null && $status >= 200 && $status < 300;

        $webhook->forceFill([
            'last_status' => $status,
            ...($succeeded
                ? ['last_delivered_at' => now()]
                : ['last_failed_at' => now()]),
        ])->save();
    }

    /**
     * The bytes we are going to send, and the bytes we are going to sign.
     *
     * Built once and handed to both, which is the only way the signature can be
     * checked at the far end — see the header above.
     */
    private function body(Contract $contract): string
    {
        $payload = [
            'event' => $this->event,
            'occurredAt' => $this->occurredAt,

            /*
             * Four fields about the contract and no more. Everything else it
             * has — the boxes, the message, who it was posted to — is either
             * ours rather than the receiver's business, or a thing they can ask
             * for over the API with a credential of their own. A webhook is a
             * nudge with enough in it to act on, not a copy of the row.
             */
            'contract' => [
                'id' => $contract->id,
                'title' => $contract->title,
                'status' => $contract->status->value,
                'completedAt' => $contract->completed_at?->toIso8601String(),
            ],

            /*
             * Everybody who was asked, every time, rather than only the one this
             * event is about. It makes one payload shape serve all three events,
             * and it answers the question a receiving system always asks next —
             * "wie moeten we nog hebben" — without a second call.
             *
             * Names and addresses travel because the receiver put them there:
             * these are the people it asked us to send the contract to. No
             * token, no IP address and no user agent — those are the audit
             * trail, and they belong to the document rather than to whoever
             * happens to subscribe.
             */
            'signers' => $contract->signers
                ->map(fn (ContractSigner $signer): array => [
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'signedAt' => $signer->signed_at?->toIso8601String(),
                    'declinedAt' => $signer->declined_at?->toIso8601String(),
                    'declineReason' => $signer->decline_reason,
                ])
                ->values()
                ->all(),

            'documentUrl' => $this->documentUrl($contract),
        ];

        $encoded = json_encode($payload);

        if ($encoded === false) {
            throw new RuntimeException("Contract {$contract->id} could not be encoded for delivery.");
        }

        return $encoded;
    }

    /**
     * Where the signed PDF can be fetched, or null when there is not one yet.
     *
     * A temporary signed URL rather than the API route, and the reason is who
     * is holding it. The API route is opened by a personal API token, which
     * acts as the member who made it — so telling a receiving system to fetch
     * the document there means it needs a credential that could also send
     * contracts, read channels and act as a person. That is a great deal of
     * authority to hand to something whose whole job is to file a PDF.
     *
     * This link is the opposite: it opens one document, it expires, and it
     * grants nothing else. It is a bearer credential — anybody who has the URL
     * has the document — which is exactly the trade every "download je bestand"
     * link in this application already makes, and the reason it is short-lived.
     *
     * Only on completed. On a signed or declined event the signed copy does not
     * exist yet, and a link that 404s is worse than no field at all — it reads
     * as a broken document rather than as one that is not finished.
     */
    private function documentUrl(Contract $contract): ?string
    {
        if ($this->event !== ContractWebhook::EVENT_COMPLETED || $contract->signedCopy() === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            ContractDocumentController::ROUTE,
            now()->addDays(self::DOCUMENT_LIFETIME_DAYS),
            ['contract' => $contract->id],
        );
    }
}
