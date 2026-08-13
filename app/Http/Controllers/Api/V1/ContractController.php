<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contracts\InstantiateTemplate;
use App\Actions\Contracts\SaveContractSigners;
use App\Actions\Contracts\SaveSignerDraft;
use App\Actions\Contracts\SendContract;
use App\Enums\ContractStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTokenWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleLocale;
use App\Http\Resources\ContractApiResource;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workspace;
use App\Workflows\GuardOutboundUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sending a prepared document to somebody, from a system that is not this one.
 *
 * The whole of the epic in one endpoint: a workspace prepares a template once —
 * the PDF, the boxes, and the sender's own signature — and after that a lease, a
 * quotation or a set of terms goes out on somebody else's trigger without a
 * person opening a screen.
 *
 * What it is not is a second implementation of sending. The call composes the
 * same three actions the screens do, in the same order: make a contract out of
 * the template, write down who is signing it, put it in the post. A contract
 * that skipped any of them would be one the audit trail could not account for.
 *
 * Ids appear in these paths, unlike everywhere else in this API. The rule they
 * break is a real one — the other endpoints are about the member behind the
 * token and need no id to say so — but a contract is a thing rather than a
 * fact about somebody, and there is no reading of "the contract" that a token
 * alone could resolve.
 */
class ContractController extends Controller
{
    use ResolvesTokenWorkspace;

    /** The same ceiling the screens have. See ContractController::MAX_SIGNERS. */
    private const MAX_RECIPIENTS = 20;

    /** Enough to catch up on since the last call, without paging. */
    private const MAX_LISTED = 100;

    /**
     * Send a template to the people who have to sign it.
     */
    public function store(
        Request $request,
        InstantiateTemplate $instantiate,
        SaveContractSigners $saveSigners,
        SaveSignerDraft $saveDraft,
        SendContract $send,
        GuardOutboundUrl $guard,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'template_id' => ['required', 'string', 'ulid'],

            // Both optional, both overriding what the template says. A machine
            // usually has a better title than "Huurovereenkomst" — it knows
            // which one.
            'title' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],

            'valid_for_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            /*
             * The language every mail about this contract goes out in. Worth a
             * field of its own here and nowhere else: from the screen, the
             * author is the reader's correspondent and their language is the
             * best guess there is, while over the API the author is the account
             * behind the token — so a rental company sending on behalf of a
             * German customer could otherwise only ask in Dutch.
             *
             * Checked against what this application actually has translations
             * for. A tag we cannot render would fall back silently, which is
             * the one answer worse than refusing it.
             */
            'locale' => ['nullable', 'string', Rule::in(HandleLocale::SUPPORTED)],

            'recipients' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*.name' => ['required', 'string', 'max:255'],
            'recipients.*.email' => ['required', 'string', 'email', 'max:255'],
            'recipients.*.values' => ['nullable', 'array'],

            /*
             * Where to be told, for this contract only. A workspace-wide
             * subscription is the other way round — see ContractWebhook — and
             * the two are not alternatives: a system that sends one contract on
             * behalf of one customer wants the answer about that contract, and
             * has nowhere to keep a subscription.
             */
            'callback_url' => ['nullable', 'string', 'url', 'max:2048'],

            /*
             * What the signature over the payload is taken with. Long enough to
             * be worth taking: a four-character secret would make the header
             * decorative.
             */
            'callback_secret' => ['nullable', 'string', 'min:16', 'max:255'],
        ]);

        $template = $this->templateFor($workspace, $validated['template_id']);

        $recipients = $this->checkedRecipients($template, $validated['recipients']);

        $callback = $this->checkedCallback($validated, $guard);

        /*
         * Everything up to and including the last row is one transaction, and
         * sending is outside it. The document is copied, the people are written
         * down and their prefilled answers are stored together or not at all —
         * but the moment mail leaves the building there is nothing to roll back,
         * which is the same line SendContract itself draws.
         */
        [$instance, $roster] = DB::transaction(function () use (
            $template, $user, $validated, $recipients, $callback, $instantiate, $saveSigners, $saveDraft,
        ): array {
            $instance = $instantiate->handle($template, $user, $validated['title'] ?? null);

            $contract = $instance->contract;

            if (($validated['message'] ?? null) !== null) {
                $contract->update(['message' => $validated['message']]);
            }

            // Written onto the contract rather than carried into the send: the
            // reminder and the signed copy leave long after this request is
            // over, and all three have to read the same answer.
            if (($validated['locale'] ?? null) !== null) {
                $contract->update(['mail_locale' => $validated['locale']]);
            }

            if ($callback !== []) {
                $contract->update($callback);
            }

            $roster = $instantiate->roster($contract, array_map(
                fn (array $recipient): array => [
                    'name' => trim($recipient['name']),
                    'email' => trim($recipient['email']),
                ],
                $recipients,
            ));

            /*
             * The rows are written here rather than left to SendContract, which
             * would write the same list a moment later. It is done early
             * because the values that were sent in have to land on somebody,
             * and there is nobody to land on until the list exists.
             */
            $saveSigners->handle($contract, $roster);

            $this->prefill($contract, $instance->fields, $recipients, $saveDraft);

            return [$instance, $roster];
        });

        $contract = $send->handle(
            contract: $instance->contract->fresh(['signers']),
            signers: $roster,
            validForDays: $validated['valid_for_days'] ?? null,
        );

        /*
         * 201, with the contract in the body. A caller that has just caused a
         * document to be sent to somebody needs the id back to follow it, and
         * the status is the difference between "we made one" and "here is the
         * one you asked about" — which matters to a client retrying a call it
         * is not sure went through.
         */
        return (new ContractApiResource($contract->fresh(['signers'])))
            /*
             * Beside the contract rather than inside it, because it is not a
             * fact about the contract but about this call: a secret the caller
             * did not choose, shown the one time it can be. Only when we minted
             * it — echoing back one they sent is how a credential ends up in
             * somebody's request log twice over.
             */
            ->additional($this->mintedSecret($validated, $callback))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The contracts this token has to keep an eye on.
     *
     * Narrowed to what the workspace sent, and then to what this member may
     * see: a token does not widen anybody. The default leaves out the finished
     * ones, because the question this endpoint is usually asked is "wat staat
     * er nog open" — pass status to ask something else.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $this->workspaceFor($request);

        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'status' => ['nullable', 'string', Rule::enum(ContractStatus::class)],
        ]);

        $status = $request->string('status')->toString();

        $contracts = Contract::query()
            ->realContracts()
            ->where('workspace_id', $workspace->id)
            ->when(
                $status !== '',
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereNot('status', ContractStatus::Draft->value),
            )
            ->with(['signers', 'workspace'])
            ->latest()
            ->limit(self::MAX_LISTED)
            ->get()
            ->filter(fn (Contract $contract): bool => $user->can('view', $contract))
            ->values();

        return ContractApiResource::collection($contracts);
    }

    public function show(Request $request, Contract $contract): ContractApiResource
    {
        return new ContractApiResource($this->readable($request, $contract));
    }

    /**
     * The template this call is about, or a flat no.
     *
     * One answer for four situations — no such row, another workspace's, a
     * contract rather than a template, and one nobody finished preparing. The
     * first three are 404 for the reason the message endpoint gives: telling
     * them apart lets a caller walk the ids to find out what exists. The
     * fourth is 422 and says so, because it is the one a caller can do
     * something about, and the templates endpoint already told them.
     */
    private function templateFor(Workspace $workspace, string $id): Contract
    {
        $template = Contract::query()
            ->templates()
            ->where('workspace_id', $workspace->id)
            ->with(['fields', 'signers'])
            ->whereKey($id)
            ->first();

        abort_if($template === null, 404, __('contracts.api.no_template'));

        abort_unless($template->isReadyToSend(), 422, __('contracts.api.template_unfinished'));

        return $template;
    }

    /**
     * The row behind an id in the path, or a 404.
     *
     * The workspace is asked first and the policy second, and both matter. The
     * workspace is what makes an id from somebody else's account useless; the
     * policy is what stops a token from seeing more than the person holding it
     * — a member who may only see their own contracts sees only those here too.
     */
    private function readable(Request $request, Contract $contract): Contract
    {
        $workspace = $this->workspaceFor($request);

        abort_if($contract->workspace_id !== $workspace->id, 404, __('contracts.api.no_contract'));
        abort_if($contract->is_template, 404, __('contracts.api.no_contract'));
        abort_unless($request->user()->can('view', $contract), 404, __('contracts.api.no_contract'));

        return $contract->load('signers');
    }

    /**
     * The recipients, checked against what the template expects.
     *
     * Three refusals live here rather than in the rules above, because all
     * three are about this template rather than about the shape of a request.
     * The count has to match exactly: a template's boxes were drawn for a
     * number of parties, and one recipient too few leaves a signature box
     * belonging to nobody while one too many hands a stranger somebody else's.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @return array<int, array<string, mixed>>
     */
    private function checkedRecipients(Contract $template, array $recipients): array
    {
        $recipients = array_values($recipients);

        if (count($recipients) !== $template->required_signers) {
            throw ValidationException::withMessages([
                'recipients' => __('contracts.api.wrong_recipient_count', [
                    'expected' => $template->required_signers,
                    'given' => count($recipients),
                ]),
            ]);
        }

        $addresses = array_map(
            fn (array $recipient): string => mb_strtolower(trim((string) $recipient['email'])),
            $recipients,
        );

        if (count(array_unique($addresses)) !== count($addresses)) {
            throw ValidationException::withMessages([
                'recipients' => __('contracts.api.duplicate_recipient'),
            ]);
        }

        /*
         * And not the author of a template they signed along with. Their row is
         * already on the contract, signed; a recipient with the same address
         * would be matched to it by SaveContractSigners and would inherit a
         * signature they never made.
         */
        $author = $template->templateSigner();

        if ($author !== null && in_array(mb_strtolower($author->email), $addresses, strict: true)) {
            throw ValidationException::withMessages([
                'recipients' => __('contracts.api.recipient_is_sender', ['email' => $author->email]),
            ]);
        }

        $this->checkedValues($template, $recipients);

        return $recipients;
    }

    /**
     * Whatever was filled in ahead of time, judged by the boxes it claims to be
     * for.
     *
     * Validated against the template rather than against the copy that does not
     * exist yet, so the errors that come back name the ids the caller was
     * given. A key nobody drew a box for is an error rather than something to
     * ignore: it almost always means the caller is filling in the wrong party.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function checkedValues(Contract $template, array $recipients): void
    {
        $offset = $template->templateSigner() === null ? 0 : 1;

        $rules = [];
        $payload = [];

        foreach ($recipients as $index => $recipient) {
            $values = $recipient['values'] ?? [];

            if (! is_array($values) || $values === []) {
                continue;
            }

            $fields = $template->fields
                ->filter(fn (ContractField $field): bool => $field->signerIndex() === $index + $offset)
                ->reject(fn (ContractField $field): bool => $field->type->isDrawn())
                ->keyBy('id');

            foreach ($values as $fieldId => $value) {
                $field = $fields->get((int) $fieldId);

                if ($field === null) {
                    throw ValidationException::withMessages([
                        "recipients.{$index}.values" => __('contracts.api.unknown_field', ['field' => $fieldId]),
                    ]);
                }

                $rules["recipients.{$index}.values.{$fieldId}"] = $field->type->rules($field, draft: true);
                $payload['recipients'][$index]['values'][$fieldId] = $value;
            }
        }

        if ($rules !== []) {
            Validator::make($payload, $rules)->validate();
        }
    }

    /**
     * The callback for this one contract, checked before anything is created.
     *
     * Through the same guard every outbound URL in this application goes
     * through, which is what keeps a callback from being a way to make the
     * server fetch its own metadata endpoint — see GuardOutboundUrl. Refused as
     * validation rather than as a 500, because from where the caller is
     * standing that is what it is.
     *
     * A URL without a secret gets one minted for it rather than being taken as
     * "do not sign this". Nothing is delivered unsigned — see
     * DeliverContractWebhooks, which skips a contract that has one and not the
     * other — so accepting a bare URL as-is would leave a caller waiting for
     * deliveries that were never going to come. The minted secret goes back in
     * the response, once, because there is nowhere to read it afterwards.
     *
     * A secret without a URL is still refused, because it would be a promise
     * nobody could keep.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function checkedCallback(array $validated, GuardOutboundUrl $guard): array
    {
        $url = $validated['callback_url'] ?? null;
        $secret = $validated['callback_secret'] ?? null;

        if ($url === null) {
            if ($secret !== null) {
                throw ValidationException::withMessages([
                    'callback_secret' => __('contracts.api.secret_without_url'),
                ]);
            }

            return [];
        }

        try {
            $url = $guard->handle($url);
        } catch (RuntimeException $refused) {
            throw ValidationException::withMessages(['callback_url' => $refused->getMessage()]);
        }

        return [
            'callback_url' => $url,
            'callback_secret' => $secret ?? 'whs_'.Str::random(48),
        ];
    }

    /**
     * The callback secret to hand back, when there is one the caller does not
     * already hold.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, string>  $callback
     * @return array<string, string>
     */
    private function mintedSecret(array $validated, array $callback): array
    {
        if ($callback === [] || ($validated['callback_secret'] ?? null) !== null) {
            return [];
        }

        return ['callbackSecret' => $callback['callback_secret']];
    }

    /**
     * Put what was sent in onto the new contract's boxes.
     *
     * Written as a draft rather than as an answer, which is the whole of the
     * meaning: filled_at stays null, so the recipient sees the values on the
     * page, may change any of them, and nothing counts as theirs until they
     * sign. A prefill that arrived stamped would be a system answering a
     * question on somebody's behalf and then asking them to sign it.
     *
     * @param  array<int, int>  $fieldMap  Template field id to its copy.
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function prefill(Contract $contract, array $fieldMap, array $recipients, SaveSignerDraft $saveDraft): void
    {
        $contract->load('fields');

        $signers = $contract->signers()->get()->keyBy('signing_order');

        $offset = $signers->has(0) && $signers->get(0)->hasSigned() ? 1 : 0;

        foreach ($recipients as $index => $recipient) {
            $values = $recipient['values'] ?? [];

            if (! is_array($values) || $values === []) {
                continue;
            }

            $signer = $signers->get($index + $offset);

            if (! $signer instanceof ContractSigner) {
                continue;
            }

            /*
             * Handed the contract it belongs to rather than left to fetch it.
             * Lazy loading is switched off in this application, and the draft
             * action reads the boxes through the signer's contract — so without
             * this the first prefilled recipient throws.
             */
            $signer->setRelation('contract', $contract);

            $translated = [];

            foreach ($values as $fieldId => $value) {
                $copy = $fieldMap[(int) $fieldId] ?? null;

                if ($copy !== null) {
                    $translated[$copy] = $value;
                }
            }

            $saveDraft->handle($signer, $translated, draft: true);
        }
    }
}
