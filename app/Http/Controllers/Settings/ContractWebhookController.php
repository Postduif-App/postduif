<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Features\Contracts as ContractsFeature;
use App\Http\Controllers\Controller;
use App\Models\ContractWebhook;
use App\Models\Workspace;
use App\Workflows\GuardOutboundUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The addresses this workspace wants told about its contracts.
 *
 * A workspace screen rather than a personal one, which is the opposite of the
 * API tokens next door and for a reason worth spelling out: a token acts as the
 * person who made it, so it is theirs alone, while a subscription describes
 * where the workspace's own news goes. The colleague who wired the accounting
 * package up leaves, and the accounting package keeps being told — see the
 * nullOnDelete on created_by.
 *
 * Only a beheerder, and only where contracts are switched on. The second gate
 * is not decoration: a screen for subscribing to events that can never fire is
 * a screen that teaches somebody the feature is broken.
 */
class ContractWebhookController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * Enough for the systems a workspace actually runs.
     *
     * A ceiling rather than no ceiling, because every one of these is an
     * outgoing request this server makes on somebody else's say-so, and a list
     * that can grow without bound is a list somebody can point at one address
     * fifty times.
     */
    private const MAX_WEBHOOKS = 10;

    public function index(Request $request): Response
    {
        $workspace = $this->workspaceWithContracts($request);

        return Inertia::render('settings/contract-webhooks', [
            /*
             * The catalogue rather than three literals in the browser: the
             * server decides which events exist and in what order they are
             * offered, and a fourth one should appear on this screen by being
             * added to ContractWebhook::EVENTS.
             */
            'events' => ContractWebhook::EVENTS,
            'webhooks' => $this->webhooksFor($workspace),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspaceWithContracts($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],

            /*
             * The shape first, and then the guard below. Laravel's url rule says
             * whether this is an address at all; GuardOutboundUrl says whether
             * it is one we are willing to go to. Both, because the messages are
             * different things a person can act on — "dat is geen adres" and
             * "dat adres is niet van buiten".
             */
            'url' => ['required', 'url:http,https', 'max:2048'],

            /*
             * At least one, or the subscription is a row that costs somebody a
             * secret and delivers nothing. Every value has to be a name we
             * actually fire, so that a typo is refused here rather than becoming
             * a subscription that silently never matches.
             */
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(ContractWebhook::EVENTS)],
        ]);

        abort_if(
            ContractWebhook::query()->where('workspace_id', $workspace->id)->count() >= self::MAX_WEBHOOKS,
            422,
            __('settings.contract_webhooks.too_many', ['count' => self::MAX_WEBHOOKS]),
        );

        if (($refusal = $this->refusalFor($validated['url'])) !== null) {
            return back()->withErrors(['url' => $refusal]);
        }

        $webhook = new ContractWebhook([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'url' => $validated['url'],
            /*
             * Put in the order the model lists them rather than the order the
             * form happened to send. The list is read on a screen and compared
             * between rows, and "signed, completed" beside "completed, signed"
             * is two subscriptions that look different and are not.
             */
            'events' => array_values(array_intersect(ContractWebhook::EVENTS, $validated['events'])),
            'created_by' => $request->user()->id,
        ]);

        $webhook->regenerateSecret();
        $webhook->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('settings.contract_webhooks.created_toast'),
        ]);

        return back();
    }

    /**
     * Switch it off, or back on.
     *
     * A timestamp rather than a delete, and the difference matters to the person
     * pressing it: an integration that is being repaired at the far end should
     * stop receiving without anybody having to write the address down first, and
     * turning it back on must not mean a new secret to paste across.
     */
    public function toggle(Request $request, ContractWebhook $contractWebhook): RedirectResponse
    {
        $workspace = $this->workspaceWithContracts($request);

        abort_unless($contractWebhook->workspace_id === $workspace->id, 404);

        $enabled = $request->boolean('enabled');

        $contractWebhook->forceFill(['disabled_at' => $enabled ? null : now()])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $enabled
                ? __('settings.contract_webhooks.enabled_toast')
                : __('settings.contract_webhooks.disabled_toast'),
        ]);

        return back();
    }

    /**
     * A new secret, and the old one dead the moment it is pressed.
     *
     * A hard cut on purpose — see ContractWebhook::regenerateSecret. Deliveries
     * between this and the far end being updated will be refused there, which is
     * exactly what should happen to a secret somebody had reason to rotate.
     */
    public function rotate(Request $request, ContractWebhook $contractWebhook): RedirectResponse
    {
        $workspace = $this->workspaceWithContracts($request);

        abort_unless($contractWebhook->workspace_id === $workspace->id, 404);

        $contractWebhook->regenerateSecret();
        $contractWebhook->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('settings.contract_webhooks.rotated_toast'),
        ]);

        return back();
    }

    /**
     * Take it away.
     *
     * Actually deleted, unlike an API token, which is kept as the record that it
     * existed. There is nothing to keep here: a subscription holds no history
     * beyond the last thing that happened to it, and an address nobody wants to
     * hear from again should stop being a row that a future delivery could
     * possibly read.
     */
    public function destroy(Request $request, ContractWebhook $contractWebhook): RedirectResponse
    {
        $workspace = $this->workspaceWithContracts($request);

        abort_unless($contractWebhook->workspace_id === $workspace->id, 404);

        $contractWebhook->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('settings.contract_webhooks.deleted_toast'),
        ]);

        return back();
    }

    /**
     * The current workspace, if this member may manage it and it has contracts.
     *
     * The feature is asked here rather than with the feature middleware, which
     * reads a {workspace} off the route — and these settings routes deliberately
     * have none. The same 404 either way.
     */
    private function workspaceWithContracts(Request $request): Workspace
    {
        $workspace = $this->currentWorkspace($request);

        abort_unless($workspace->hasFeature(ContractsFeature::class), 404);

        return $workspace;
    }

    /**
     * Why this address will not do, or null when it will.
     *
     * The guard's own words, which are already written for somebody reading a
     * screen rather than a stack trace — see GuardOutboundUrl, where the message
     * for every refusal is a translated line for exactly this moment.
     */
    private function refusalFor(string $url): ?string
    {
        try {
            app(GuardOutboundUrl::class)->handle($url);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function webhooksFor(Workspace $workspace): array
    {
        return ContractWebhook::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ContractWebhook $webhook): array => [
                'id' => $webhook->id,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'events' => $webhook->events,

                /*
                 * Readable again, the same trade the tokens and the incoming
                 * webhooks make: a secret you cannot look up is a secret you
                 * lose by closing the tab, and losing it means rotating it,
                 * which means an integration that stops working until somebody
                 * notices. It never leaves this method by accident — the model
                 * hides the attribute, so showing it is always a decision.
                 */
                'secret' => $webhook->secret,

                'lastDeliveredAt' => $webhook->last_delivered_at?->toIso8601String(),
                'lastFailedAt' => $webhook->last_failed_at?->toIso8601String(),
                'lastStatus' => $webhook->last_status,
                'disabledAt' => $webhook->disabled_at?->toIso8601String(),
            ])
            ->all();
    }
}
