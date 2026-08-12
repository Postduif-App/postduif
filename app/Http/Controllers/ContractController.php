<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Contracts\CreateContract;
use App\Actions\Contracts\PdfRefused;
use App\Actions\Contracts\RemindContractSigners;
use App\Actions\Contracts\SaveContractFields;
use App\Actions\Contracts\SendContract;
use App\Enums\ContractFieldType;
use App\Http\Requests\StoreContractRequest;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Starting a contract, drawing the boxes over it, and handing the document back
 * out.
 *
 * Naming the signers and everything after that live elsewhere. What this file
 * has to get right is that a PDF gets in safely, that what is drawn on it can
 * only be drawn by somebody allowed to, and that it comes back out to the right
 * people.
 */
class ContractController extends Controller
{
    public function __construct(private readonly BuildChatShell $buildChatShell) {}

    /**
     * More boxes than any contract has, and few enough that one save stays one
     * save. Forty fields on fifty pages is already an unusual document.
     */
    private const MAX_FIELDS = 200;

    /**
     * More people than any one document is signed by, and few enough that the
     * mail loop stays a loop rather than a job.
     */
    private const MAX_SIGNERS = 20;

    /**
     * Take the uploaded PDF and open a draft on it.
     *
     * A redirect rather than JSON, because this is a form being submitted and
     * the next thing that happens is a whole new screen — the editor, with the
     * document rendered in it.
     */
    public function store(
        StoreContractRequest $request,
        Workspace $workspace,
        CreateContract $createContract,
    ): RedirectResponse {
        try {
            $contract = $createContract->handle(
                workspace: $workspace,
                author: $request->user(),
                file: $request->file('file'),
                title: $request->string('title')->toString(),
                message: $request->input('message'),
                validForDays: $request->integer('valid_for_days') ?: null,
            );
        } catch (PdfRefused $refused) {
            /*
             * Back to the form with the reason on the file field, which is where
             * the person is looking.
             *
             * A refusal here is not an error in the sense that anything went
             * wrong — it is validation that happened to need Ghostscript to
             * reach a verdict, and it should read to the author exactly like
             * "dit is geen PDF" does. Every message this can carry is written
             * for that — see the contracts.upload keys in the language files.
             */
            return back()
                ->withInput($request->except('file'))
                ->withErrors(['file' => $refused->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.contract.created'),
        ]);

        /*
         * Straight into the editor rather than back to the list. A contract
         * with a document and no boxes on it cannot be signed, so the list is
         * never where anybody wanted to end up — the same reasoning the form
         * builder redirects on.
         */
        return to_route('chat.contracts.edit', [$workspace, $contract]);
    }

    /**
     * One link in a channel, two destinations.
     *
     * A contract card is drawn once and broadcast to everybody in the channel
     * at the same moment, so it cannot know who is looking at it. This is the
     * first point where the viewer is known, and so this is where the question
     * is answered — the same shape SecretFillController::show has.
     *
     * A signer who still has something to do goes to their own page. Everybody
     * else who is allowed to look sees the contract itself. Anybody else has no
     * business here at all, which is what the policy says a moment later.
     */
    public function show(Request $request, Workspace $workspace, Contract $contract): Response|RedirectResponse
    {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $user = $request->user();

        $mine = $contract->signers()
            ->where('user_id', $user->id)
            ->get()
            ->first(fn (ContractSigner $signer): bool => $signer->canStillSign());

        if ($mine !== null) {
            /*
             * Their own token, not the contract's id. There is no other way in:
             * the signing page has no notion of a session, because most people
             * who reach it have no account.
             */
            return redirect()->route('contracts.sign.show', $mine->token);
        }

        $this->authorize('view', $contract);

        $contract->load(['signers', 'author:id,name']);

        return Inertia::render('chat/contract-show', [
            ...$this->buildChatShell->handle($workspace, $user),

            'contract' => [
                'id' => $contract->id,
                'title' => $contract->title,
                'message' => $contract->message,
                'status' => $contract->status->value,
                'statusLabel' => $contract->status->label(),
                'statusDescription' => $contract->status->description(),
                'pageCount' => $contract->page_count,
                'authorName' => $contract->author?->name,
                'createdAt' => $contract->created_at?->toIso8601String(),
                'expiresAt' => $contract->expires_at?->toIso8601String(),
                'completedAt' => $contract->completed_at?->toIso8601String(),

                'signedCount' => $contract->signedCount(),
                'signerCount' => $contract->signers->count(),

                /*
                 * Whether the finished document is ready, still coming, or went
                 * wrong. Three answers rather than a link-or-not, because
                 * "nog even geduld" and "dit is misgegaan" are different things
                 * to tell somebody — see Contract::signedCopyState.
                 */
                'signedCopyState' => $contract->signedCopyState(),

                'sourceUrl' => route('chat.contracts.source', [$workspace, $contract]),
                'downloadUrl' => $contract->signedCopy() === null
                    ? null
                    : route('chat.contracts.download', [$workspace, $contract]),

                /*
                 * Here the names *are* carried, unlike on the card in the
                 * channel. This screen is behind the policy — the author or
                 * somebody who runs the workspace — and knowing who has not
                 * signed yet is the entire reason they opened it.
                 */
                'signers' => $contract->signers->map(fn (ContractSigner $signer): array => [
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'openedAt' => $signer->opened_at?->toIso8601String(),
                    'signedAt' => $signer->signed_at?->toIso8601String(),
                    'declinedAt' => $signer->declined_at?->toIso8601String(),
                    'declineReason' => $signer->decline_reason,
                    'remindedAt' => $signer->reminded_at?->toIso8601String(),

                    'state' => match (true) {
                        $signer->hasSigned() => 'signed',
                        $signer->hasDeclined() => 'declined',
                        $signer->opened_at !== null => 'opened',
                        default => 'waiting',
                    },
                ])->all(),
            ],

            'can' => [
                'remind' => $user->can('remind', $contract),
                'cancel' => $user->can('cancel', $contract),
                'update' => $user->can('update', $contract),
            ],

            'workspaceSlug' => $workspace->slug,
        ]);
    }

    /**
     * The editor: the document with whatever has been drawn over it so far.
     *
     * A screen of its own rather than a panel in a list, the same choice the
     * form builder makes and for a stronger reason — laying out a contract is
     * done with the document in front of you at a readable size, and it takes
     * more than one sitting.
     */
    public function edit(Request $request, Workspace $workspace, Contract $contract): Response
    {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('update', $contract);

        $contract->load(['fields', 'signers', 'author:id,name']);

        return Inertia::render('chat/contract-edit', [
            ...$this->buildChatShell->handle($workspace, $request->user()),

            'contract' => [
                'id' => $contract->id,
                'title' => $contract->title,
                'message' => $contract->message,
                'status' => $contract->status->value,
                'statusLabel' => $contract->status->label(),
                'pageCount' => $contract->page_count,
                'expiresAt' => $contract->expires_at?->toDateString(),

                /*
                 * Where pdf.js fetches the document. A route rather than a
                 * URL to the file, because the file is on the private disk and
                 * this is the only way to it — see source() below.
                 */
                'sourceUrl' => route('chat.contracts.source', [$workspace, $contract]),

                'fields' => $contract->fields->map(fn (ContractField $field): array => [
                    'id' => $field->id,
                    'page' => $field->page,

                    // Cast to float on the way out: a decimal column comes back
                    // from PDO as a string, and the editor does arithmetic on
                    // these the moment somebody drags one.
                    'x' => (float) $field->x,
                    'y' => (float) $field->y,
                    'width' => (float) $field->width,
                    'height' => (float) $field->height,

                    'type' => $field->type->value,
                    'label' => $field->label,
                    'isRequired' => $field->is_required,
                    'signerIndex' => $field->signer_index,
                ])->all(),

                /*
                 * Who the boxes can be handed to, by their place in the queue.
                 * Empty while the contract is a fresh draft, which is the
                 * ordinary case here — the editor then simply does not offer
                 * the choice, because a contract with one signer has one answer.
                 */
                'signers' => $contract->signers->map(fn (ContractSigner $signer): array => [
                    'index' => $signer->signing_order,
                    'name' => $signer->name,
                ])->all(),
            ],

            /*
             * The vocabulary of box types, from the enum rather than from a
             * list in the editor — the same reasoning the form builder takes
             * its catalogue from FormFieldType for.
             *
             * defaultSize rides along because it is a fact about the type, and
             * the browser needs it the moment somebody drops a new box: a fresh
             * signature field should already be signature-shaped.
             */
            'fieldTypes' => array_map(fn (ContractFieldType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'isDrawn' => $type->isDrawn(),
                ...$type->defaultSize(),
            ], ContractFieldType::cases()),

            'workspaceSlug' => $workspace->slug,
        ]);
    }

    /**
     * Save the boxes.
     *
     * Only the boxes. The title, the deadline and the people are edited
     * elsewhere: this endpoint is what an editor full of dragged rectangles
     * posts, and mixing a text field into it would mean a stray keystroke
     * riding along with a layout change.
     */
    public function updateFields(
        Request $request,
        Workspace $workspace,
        Contract $contract,
        SaveContractFields $saveFields,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        /*
         * update rather than a rule of its own, and that is the whole
         * safeguard: the policy stops allowing this the moment anybody has
         * signed. Moving a signature box after somebody signed would change
         * what they agreed to between reading it and signing it.
         */
        $this->authorize('update', $contract);

        $data = $request->validate([
            'fields' => ['present', 'array', 'max:'.self::MAX_FIELDS],
            'fields.*.id' => ['nullable', 'integer'],

            /*
             * Bounded by what the document actually has. A box on page nine of
             * a five-page contract is not a validation nicety — it is a field
             * nobody can ever fill in, because there is no page to draw it on.
             */
            'fields.*.page' => ['required', 'integer', 'min:1', 'max:'.max(1, $contract->page_count)],

            'fields.*.x' => ['required', 'numeric', 'between:0,1'],
            'fields.*.y' => ['required', 'numeric', 'between:0,1'],
            'fields.*.width' => ['required', 'numeric', 'between:0,1'],
            'fields.*.height' => ['required', 'numeric', 'between:0,1'],

            'fields.*.type' => ['required', Rule::enum(ContractFieldType::class)],
            'fields.*.label' => ['required', 'string', 'max:200'],
            'fields.*.is_required' => ['sometimes', 'boolean'],

            /*
             * Which signer, by position. Null means the first — see
             * ContractField::signerIndex. Bounded by the list that exists,
             * because a box for the fourth signer of a two-signer contract is
             * one nobody will ever be shown.
             */
            'fields.*.signer_index' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.max(0, $contract->signers()->count() - 1),
            ],
        ]);

        $saveFields->handle($contract, $data['fields']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.contract.fields_saved')]);

        return back();
    }

    /**
     * Name the people and put it in the post.
     *
     * The step that turns a draft into something the outside world is holding.
     * Everything about it that can fail — an address that bounces, a transport
     * that is down — happens after the transaction, because a mail is the one
     * side effect there is no rollback for. See SendContract.
     */
    public function send(
        Request $request,
        Workspace $workspace,
        Contract $contract,
        SendContract $sendContract,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        /*
         * update rather than a right of its own, and it does two jobs at once:
         * it asks whether this person may touch this contract, and it refuses
         * on anything that is not still a draft or already out. Sending a
         * withdrawn contract again would hand out live links to something the
         * author stopped.
         */
        $this->authorize('update', $contract);

        $data = $request->validate([
            'signers' => ['required', 'array', 'min:1', 'max:'.self::MAX_SIGNERS],
            'signers.*.name' => ['required', 'string', 'max:120'],
            'signers.*.email' => ['required', 'email', 'max:255'],

            /*
             * A colleague picked from the workspace rather than an address
             * typed in. Scoped to this workspace in the rule itself: an id from
             * somewhere else must not be storable, and a validator is the only
             * place that refusal reads as a validation error rather than a 403.
             */
            'signers.*.user_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],

            'valid_for_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            /*
             * Where the news lands. Scoped to this workspace in the rule
             * itself, the way a form's notify channel is: an id from somewhere
             * else must not be storable, and a validator is the only place that
             * refusal reads as a validation error rather than as a 403.
             */
            'notify_channel_id' => [
                'nullable',
                Rule::exists('channels', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);

        /*
         * The same address twice is two links to one inbox — and two rows
         * claiming to be the person who signed. Refused here rather than left
         * to the unique index, so it reads as a mistake somebody can correct
         * instead of as a database error.
         */
        $addresses = array_map(
            fn (array $signer): string => mb_strtolower(trim($signer['email'])),
            $data['signers'],
        );

        if (count(array_unique($addresses)) !== count($addresses)) {
            return back()->withErrors(['signers' => __('contracts.send.duplicate_address')]);
        }

        abort_unless($contract->hasSource(), 404);

        $sendContract->handle(
            contract: $contract,
            signers: $data['signers'],
            validForDays: $data['valid_for_days'] ?? null,
            notifyChannelId: $data['notify_channel_id'] ?? null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('flashes.contract.sent', count($data['signers'])),
        ]);

        return back();
    }

    /**
     * Nudge whoever has not answered.
     *
     * Its own endpoint rather than a flag on the one above, because the two are
     * different acts: sending decides who is asked, reminding asks the same
     * people again. The throttle that keeps this from becoming harassment is in
     * the action, where it belongs — it is about how often rather than who.
     */
    public function remind(
        Workspace $workspace,
        Contract $contract,
        RemindContractSigners $remind,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('remind', $contract);

        $reminded = $remind->handle($contract);

        /*
         * Zero is an ordinary answer rather than a failure — everybody has
         * either signed or was nudged this morning — and the page says which,
         * instead of claiming a mail went out that did not.
         */
        Inertia::flash('toast', [
            'type' => $reminded === 0 ? 'info' : 'success',
            'message' => $reminded === 0
                ? __('flashes.contract.nobody_to_remind')
                : trans_choice('flashes.contract.reminded', $reminded),
        ]);

        return back();
    }

    /**
     * The finished article, for whoever asked for the signatures.
     *
     * A route apart from source(), although both hand over a PDF from the same
     * private disk. What they are is different: the source is the document as
     * it was sent, which the editor and the signing page both need to render,
     * and this is the record of what happened to it. Somebody comparing the two
     * is doing exactly the thing the audit trail is for.
     *
     * Offered as a download rather than inline, because this one is not there
     * to be looked at in a tab — it is the copy that goes into a folder.
     */
    public function download(Workspace $workspace, Contract $contract): BinaryFileResponse
    {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('download', $contract);

        $media = $contract->signedCopy();

        /*
         * A 404 while it is still being composed, and that is the honest
         * answer: there is nothing here yet. The overview knows the difference
         * between "nog bezig" and "het is misgegaan" — see
         * Contract::signedCopyState — and this route is not where somebody
         * should be learning which it is.
         */
        abort_if($media === null || ! is_file($media->getPath()), 404);

        $response = response()->file($media->getPath(), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($media->file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    /**
     * The PDF itself, for the editor and for the signing page to render.
     *
     * Inline rather than as a download, because both of those load it into
     * pdf.js rather than offering it to the reader — ?download=1 is there for
     * the author who wants the file itself.
     *
     * Streamed from the private disk through a policy, and there is no other
     * way to it. That is the whole reason this method exists: a contract on a
     * public disk would be one guessed URL away from a stranger, and the URL
     * would keep working after the contract was withdrawn, which is every limit
     * the feature has.
     */
    public function source(
        Request $request,
        Workspace $workspace,
        Contract $contract,
    ): BinaryFileResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('download', $contract);

        $media = $contract->source();

        abort_if($media === null, 404);

        $path = $media->getPath();

        abort_unless(is_file($path), 404);

        $inline = ! $request->boolean('download');

        $response = response()->file($path, [
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($media->file_name).'"',

            /*
             * No guessing around the type. This route sits on the application's
             * own origin, and a browser that sniffed its way to "this is really
             * HTML" would run whatever it found there as us.
             *
             * That should be impossible — NormalisePdf rewrites every upload
             * through Ghostscript and refuses anything executable that survives
             * — but the whole point of that pass is that a PDF is not to be
             * taken at its word, and neither is this file just because it went
             * through it.
             */
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Set on the response rather than handed in: a file response fills in
        // Content-Type from the bytes on disk, which is a second opinion we did
        // not ask for.
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
