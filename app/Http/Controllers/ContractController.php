<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Contracts\CancelContract;
use App\Actions\Contracts\CreateContract;
use App\Actions\Contracts\DuplicateContract;
use App\Actions\Contracts\PdfRefused;
use App\Actions\Contracts\PostContractToChannel;
use App\Actions\Contracts\RemindContractSigners;
use App\Actions\Contracts\SaveContractFields;
use App\Actions\Contracts\SaveContractSigners;
use App\Actions\Contracts\SendContract;
use App\Actions\Contracts\SendSignedContract;
use App\Actions\Contracts\SetTemplateAuthor;
use App\Actions\Contracts\SigningRefused;
use App\Enums\ContractFieldType;
use App\Enums\ContractStatus;
use App\Enums\WorkspaceAbility;
use App\Http\Requests\StoreContractRequest;
use App\Jobs\RenderSignedContractJob;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
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

    /** Enough to catch up on; older than this and the search is the place. */
    private const MAX_LISTED = 100;

    /**
     * Take the uploaded PDF and open a draft on it.
     *
     * A redirect rather than JSON, because this is a form being submitted and
     * the next thing that happens is a whole new screen — the editor, with the
     * document rendered in it.
     *
     * Whether it is a template is decided here, by a tick on the upload box, and
     * deliberately nowhere else. The alternative — an "opslaan als sjabloon"
     * button on a contract that already exists — reads as the cheaper option and
     * is not: a real contract carries named signers with tokens and answers, and
     * a template carries a count and at most the author, so converting one means
     * throwing away rows that look exactly like people who were asked and never
     * replied, and then guessing what the number was supposed to be. It also
     * duplicates something the application already does well — DuplicateContract
     * is the answer to "deze wil ik nog eens sturen", and a template is the
     * answer to "deze ga ik honderd keer sturen".
     *
     * Which leaves the tick where the decision is actually made. Somebody
     * uploading a standard lease knows before they choose the file that this is
     * the mould rather than the letter.
     */
    public function store(
        StoreContractRequest $request,
        Workspace $workspace,
        CreateContract $createContract,
    ): RedirectResponse {
        $asTemplate = $request->boolean('as_template');

        try {
            $contract = $createContract->handle(
                workspace: $workspace,
                author: $request->user(),
                file: $request->file('file'),
                title: $request->string('title')->toString(),
                message: $request->input('message'),
                validForDays: $request->integer('valid_for_days') ?: null,
                asTemplate: $asTemplate,
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

        /*
         * A sentence of its own for a template, because the next step is a
         * different one. A contract wants its signers named; a template wants a
         * number of recipients and the author's own signature, and telling
         * somebody to "zet nu de invulvakken op de pagina's" would send them
         * past the one screen that explains what they are holding.
         */
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __($asTemplate ? 'flashes.contract.template_created' : 'flashes.contract.created'),
        ]);

        /*
         * On to the contract's own page rather than back to the list, and to
         * that page rather than straight into the editor.
         *
         * The list is never where anybody wanted to end up: a contract with a
         * document and no boxes on it cannot be signed, so uploading is the
         * first half of one action and the redirect owes somebody the second.
         *
         * Which second half is the part worth stating. The editor was the
         * obvious answer and it is the wrong one, because "in te vullen door"
         * is a choice between numbers until the signers have names — a box laid
         * out for the tenant on a contract where nobody has been named is a box
         * assigned to "ondertekenaar 2", and the person drawing it has to hold
         * in their head which number is whom. Naming them first turns the whole
         * of that panel from numbers into people. It is also the answer to a
         * question that is already in mind at upload: somebody who has just
         * chosen a PDF knows who they are sending it to.
         *
         * The editor is one click away from there and stays where it was — see
         * the contract page's own header.
         */
        return to_route('chat.contracts.show', [$workspace, $contract]);
    }

    /**
     * Every contract this person is allowed to see.
     *
     * Filtered in SQL rather than fetched and then sieved through the policy,
     * because "wat mag ik zien" is a fact about a query when it decides what a
     * page shows: a member who may only see their own would otherwise page
     * through a list that is mostly gaps.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        /*
         * The same question the rail asked before it drew the entry — see
         * WorkspacePolicy::createContract. Asking a different one here is how a
         * button ends up leading to a 403.
         */
        $this->authorize('createContract', $workspace);

        $manages = $workspace->allows($user, WorkspaceAbility::ManageWorkspace);

        $terms = $request->string('q')->trim()->value();

        $contracts = $workspace->contracts()
            ->withCount([
                'signers',
                'signers as signed_count' => fn ($query) => $query->whereNotNull('signed_at'),
            ])
            ->with('author:id,name')
            /*
             * The same line ContractPolicy::view draws, in SQL. Somebody who
             * runs the workspace sees everything, because a contract sent to
             * the wrong address has to be stoppable by somebody who is still
             * around. Everybody else sees their own.
             */
            ->unless($manages, fn ($query) => $query->where('created_by', $user->id))
            ->when($terms !== '', $this->matching($terms))

            /*
             * And nothing that is a mould rather than a letter.
             *
             * The one line in this method that is easy to forget and impossible
             * to notice afterwards: a template has a status of Draft like any
             * unsent contract, so without this it would sit in the list looking
             * exactly like something somebody forgot to send — and the delete
             * button beside it would throw away the document a hundred future
             * contracts are made from.
             */
            ->realContracts()
            ->latest('created_at')
            ->limit(self::MAX_LISTED)
            ->get();

        /*
         * The moulds, fetched separately rather than sorted out of one list.
         *
         * Two queries because the two lists answer different questions and are
         * shown apart — a contract is asked "hoe ver staat het", a template is
         * asked "kan hij gebruikt worden" — and because a template's answer
         * needs its boxes and its one signer in hand, which is a load every real
         * contract in the list would otherwise pay for and never use.
         */
        $templates = $workspace->contracts()
            ->with(['author:id,name', 'fields', 'signers', 'media'])
            ->unless($manages, fn ($query) => $query->where('created_by', $user->id))
            ->when($terms !== '', $this->matching($terms))
            ->templates()
            ->latest('created_at')
            ->limit(self::MAX_LISTED)
            ->get();

        /*
         * The workspace they all came from, handed back to them.
         *
         * ContractPolicy::view reaches for $contract->workspace when the viewer
         * is not the author, and every row here came out of this one — so
         * without this, asking the policy per row is a hundred queries for a
         * model already in hand (and, outside production, a lazy-loading
         * exception rather than a slow page).
         */
        $contracts->each(fn (Contract $contract) => $contract->setRelation('workspace', $workspace));
        $templates->each(fn (Contract $template) => $template->setRelation('workspace', $workspace));

        return Inertia::render('chat/contracts', [
            ...$this->buildChatShell->handle($workspace, $user),

            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'title' => $contract->title,
                'status' => $contract->status->value,
                'statusLabel' => $contract->status->label(),
                'authorName' => $contract->author?->name,
                'createdAt' => $contract->created_at?->toIso8601String(),
                'expiresAt' => $contract->expires_at?->toIso8601String(),
                'signerCount' => $contract->signers_count,
                'signedCount' => $contract->signed_count,

                /*
                 * Read here rather than left to the column, for the reason the
                 * chat card reads it: a deadline that passed an hour ago has
                 * passed, whether or not the nightly command has been round.
                 */
                'hasExpired' => $contract->hasExpired(),

                /*
                 * Asked per row rather than derived in the list from the status,
                 * because the answer is three things at once — the policy's line
                 * about who, the status, and whether this workspace gave this
                 * role the right to delete a finished contract — and a screen
                 * that works any of them out for itself is a screen that drifts
                 * away from the rest. Cheap here: view() is already settled for
                 * everybody in this list by the query above.
                 */
                'canDelete' => $user->can('delete', $contract),
            ])->all(),

            /*
             * A template says four things, and none of them is a status. It has
             * none worth showing — it is a draft forever, by definition — so
             * what the row has to answer instead is whether it can be used yet
             * and, when it cannot, roughly how far off it is: how many parties
             * it is laid out for, and whether the author's own signature is on
             * it.
             */
            'templates' => $templates->map(fn (Contract $template): array => [
                'id' => $template->id,
                'title' => $template->title,
                'authorName' => $template->author?->name,
                'createdAt' => $template->created_at?->toIso8601String(),
                'partyCount' => $template->partyCount(),

                /*
                 * Whether the author put themselves on it at all, and whether
                 * they have actually signed. Two answers rather than one,
                 * because "ik teken zelf mee" and "ik heb dat ook gedaan" are
                 * different places to be stuck, and only the second is a thing
                 * the reader still has to go and do.
                 */
                'signsAlong' => $template->templateSigner() !== null,
                'authorSigned' => $template->templateSigner()?->hasSigned() ?? false,

                'isReadyToSend' => $template->isReadyToSend(),
                'canDelete' => $user->can('delete', $template),
            ])->all(),

            /*
             * Handed back rather than left to the browser to remember. The box
             * has to still hold what was typed once the answer lands, and what
             * lands is a fresh set of props — so the server is the only thing
             * that knows which list this is.
             */
            'search' => $terms,

            'workspaceSlug' => $workspace->slug,

            /*
             * The two limits the upload box says out loud, read from the same
             * config StoreContractRequest and NormalisePdf enforce. A number
             * typed into the interface would be a promise the server had never
             * agreed to, and the day somebody raised CONTRACTS_MAX_UPLOAD_KB it
             * would quietly start lying.
             */
            'maxUploadBytes' => (int) config('contracts.max_upload_kilobytes') * 1024,
            'maxPages' => (int) config('contracts.max_pages'),
        ]);
    }

    /**
     * Narrow the list to what somebody typed into the box.
     *
     * Two questions at once, because they are the two ways a person remembers a
     * contract: what it was called, and who it went to. Somebody looking for
     * "de huurovereenkomst" types the title; somebody looking for "wat heb ik
     * ooit naar jan@example.com gestuurd" has only the address, and a search
     * that only read titles would answer nothing for the second.
     *
     * @return callable(Builder<Contract>): void
     */
    private function matching(string $terms): callable
    {
        /*
         * Escaped before it becomes a pattern. % and _ are wildcards to LIKE,
         * so an unescaped "%" typed into the box would quietly match every
         * contract in the workspace — a search that answers "alles" reads like
         * a broken filter rather than like a wildcard nobody asked for.
         */
        $like = '%'.addcslashes($terms, '%_\\').'%';

        return function (Builder $query) use ($like): void {
            $query->where(fn (Builder $match) => $match
                ->where('contracts.title', 'ilike', $like)
                /*
                 * whereHas rather than a join: a contract with three signers
                 * whose addresses all contain the terms is still one row, and a
                 * join would list it three times.
                 */
                ->orWhereHas('signers', fn (Builder $signers) => $signers
                    ->where(fn (Builder $who) => $who
                        ->where('contract_signers.name', 'ilike', $like)
                        ->orWhere('contract_signers.email', 'ilike', $like))));
        };
    }

    /**
     * Stop it.
     *
     * What this does not do is rotate the tokens — see CancelContract, where
     * the reasoning is written down: the links have to keep resolving so that
     * the person holding one is told it was withdrawn rather than left with a
     * 404 to interpret.
     */
    public function cancel(Workspace $workspace, Contract $contract, CancelContract $cancel): RedirectResponse
    {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('cancel', $contract);

        try {
            $cancel->handle($contract);
        } catch (SigningRefused $refused) {
            return back()->withErrors(['contract' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.contract.cancelled')]);

        return back();
    }

    /**
     * Use it again, for other people.
     *
     * The answer to the thing a completed contract cannot do. Editing it has
     * been forbidden since the first signature landed — see ContractPolicy —
     * so the way to send the same lease to next month's tenant is to make a
     * fresh draft of it, which is what this does: the document and the boxes,
     * none of the history.
     *
     * Straight to the new contract's own screen rather than back here, because
     * the next thing to do is name the people, and that panel is there.
     */
    public function duplicate(
        Request $request,
        Workspace $workspace,
        Contract $contract,
        DuplicateContract $duplicate,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('duplicate', $contract);

        // Nothing to copy. A row whose PDF never arrived is the one case the
        // action cannot do anything with — see there.
        abort_unless($contract->hasSource(), 404);

        /*
         * The title is asked for rather than derived, and required rather than
         * optional. This is the only moment a contract is ever named: there is
         * no screen that renames one afterwards, so a copy that quietly
         * inherited "Huurovereenkomst 2026" would sit in the list next to the
         * original with no way to tell them apart.
         */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
        ]);

        $contract->load('fields');

        $copy = $duplicate->handle($contract, $request->user(), $data['title']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.contract.duplicated'),
        ]);

        return to_route('chat.contracts.show', [$workspace, $copy]);
    }

    /**
     * Throw it away.
     *
     * The act cancel() is deliberately not. Withdrawing leaves the record
     * standing and every link resolving, so that whoever holds one is told it
     * was stopped; this takes the row, the PDF and the signatures off the disk
     * and leaves those links resolving to nothing at all. Which is why it is
     * offered mainly for the drafts and the fizzled-out correspondence that
     * otherwise sit in the list until the prune command gets round to them.
     *
     * A finished contract goes this way only for a role a workspace has
     * deliberately given the right to — see ContractPolicy::delete and
     * WorkspaceAbility::DeleteSignedContracts. The signers hold a copy and may
     * assume ours still exists, so that is a decision somebody makes on
     * purpose rather than one that comes with running the place.
     *
     * Everything hanging off it goes with it and nothing here has to say so:
     * the signers are taken down by Contract::booted, one at a time so that the
     * media library actually removes their signature files, and the media
     * library takes the two PDFs on the contract's own delete event.
     *
     * Back to the list rather than back(), because back() is this contract's
     * own screen and that screen no longer exists.
     */
    public function destroy(Workspace $workspace, Contract $contract): RedirectResponse
    {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('delete', $contract);

        $contract->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.contract.deleted')]);

        return to_route('chat.contracts.index', $workspace);
    }

    /**
     * Put it in a channel.
     *
     * Its own endpoint rather than part of sending, because the two address
     * different people: sending asks the signers, this tells the colleagues.
     * The channel's own rule about who may post is asked at the point where the
     * channel is known — being allowed to see a contract is not being allowed
     * to write in a room.
     */
    public function post(
        Request $request,
        Workspace $workspace,
        Contract $contract,
        PostContractToChannel $post,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('view', $contract);

        $request->validate([
            'channel_id' => ['required', 'integer'],
        ]);

        $channel = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($request->integer('channel_id'));

        $this->authorize('post', $channel);

        $post->handle($contract, $channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.contract.posted')]);

        return back();
    }

    /**
     * Have another go at composing the signed copy.
     *
     * Offered because the ways this fails are mostly temporary — a disk that
     * was full, a worker killed mid-run — and because the alternative is a
     * contract that is properly signed and permanently without its document.
     *
     * The flag is cleared before the job is queued rather than after it
     * succeeds, so the screen stops saying "misgegaan" the moment somebody has
     * asked for another attempt. If it fails again, failed() puts it back.
     */
    public function retryRender(Workspace $workspace, Contract $contract): RedirectResponse
    {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('download', $contract);

        abort_unless($contract->signedCopyState() === 'failed', 404);

        $contract->forceFill(['render_failed_at' => null])->save();

        RenderSignedContractJob::dispatch($contract->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.contract.retrying')]);

        return back();
    }

    /**
     * Post the finished document to everybody who signed it, again.
     *
     * It has already gone out once by itself, the moment the copy was composed
     * — see RenderSignedContractJob. This exists because mail goes missing, and
     * the only useful answer to "ik heb hem nooit ontvangen" is to send it
     * rather than to look up whether it was sent.
     *
     * So it deliberately ignores the stamps the automatic send respects: asked
     * by hand, everybody who signed gets it again. Behind download() rather than
     * remind(), because what it hands over is the document — and remind() says
     * no to a finished contract, which is the only kind this works on.
     */
    public function sendSignedCopy(
        Workspace $workspace,
        Contract $contract,
        SendSignedContract $sendCopies,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('download', $contract);

        // Nothing to send until the copy exists. A 404 rather than a flash: the
        // button is not drawn in any other state, so arriving here means the
        // request was not made by the screen.
        abort_unless($contract->signedCopyState() === 'ready', 404);

        $sent = $sendCopies->handle($contract, again: true);

        Inertia::flash('toast', [
            /*
             * Zero is an ordinary answer and gets an ordinary tone: a contract
             * everybody refused is finished business with nobody to send
             * anything to, and calling that a success would be claiming mail
             * went out that did not.
             */
            'type' => $sent === 0 ? 'info' : 'success',
            'message' => $sent === 0
                ? __('flashes.contract.nobody_to_send_copy')
                : trans_choice('flashes.contract.copy_sent', $sent),
        ]);

        return back();
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

        /*
         * Somebody who is only a signer goes straight to their own page.
         *
         * Their own token, not the contract's id. There is no other way in:
         * the signing page has no notion of a session, because most people who
         * reach it have no account.
         *
         * Whoever may also manage the contract does not get bounced, and that
         * exception exists for one case: an author who put themselves on the
         * list. Sending them to their signing page would be taking away the
         * screen that can remind and withdraw until they have signed — from the
         * one person who is most likely to need it. They get the way to their
         * own page as a link instead, below.
         */
        if ($mine !== null && $user->cannot('view', $contract)) {
            return redirect()->route('contracts.sign.show', $mine->token);
        }

        $this->authorize('view', $contract);

        // The boxes ride along only for a template, which is the one thing on
        // this screen that has to know whether any have been drawn — see
        // Contract::isReadyToSend. A real contract's detail page never counts
        // them, and loading them for every one would be a query nobody reads.
        $contract->load($contract->isTemplate()
            ? ['signers', 'author:id,name', 'fields']
            : ['signers', 'author:id,name']);

        // The one it came out of, so the four policy questions below do not each
        // go and fetch it again — the same reason index() does it.
        $contract->setRelation('workspace', $workspace);

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

                /*
                 * The way to this person's own signing page, when they are on
                 * the list themselves and have not answered yet.
                 *
                 * An author who ticked "ik onderteken zelf ook" is the case
                 * this is for. Their token is in it, which is why it is only
                 * ever built for the person it belongs to — see the redirect
                 * above for the other half of the same decision.
                 */
                'mySignUrl' => $mine?->signUrl(),

                'sourceUrl' => route('chat.contracts.source', [$workspace, $contract]),

                /*
                 * Two adresses to the same route and the same policy, because
                 * reading the finished document and filing it away are two
                 * different errands — see download() for which way round the
                 * flag points.
                 */
                'downloadUrl' => $contract->signedCopy() === null
                    ? null
                    : route('chat.contracts.download', [$workspace, $contract]),
                'signedCopyUrl' => $contract->signedCopy() === null
                    ? null
                    : route('chat.contracts.download', [$workspace, $contract, 'inline' => 1]),

                /*
                 * Here the names *are* carried, unlike on the card in the
                 * channel. This screen is behind the policy — the author or
                 * somebody who runs the workspace — and knowing who has not
                 * signed yet is the entire reason they opened it.
                 */
                'signers' => $contract->signers->map(fn (ContractSigner $signer): array => [
                    'name' => $signer->name,
                    'email' => $signer->email,

                    /*
                     * Carried so that the form can hand the same list back
                     * unchanged. Losing it on the way through the screen would
                     * quietly turn a colleague into an outsider — the id is
                     * what makes the DM about this contract possible.
                     */
                    'userId' => $signer->user_id,
                    'openedAt' => $signer->opened_at?->toIso8601String(),
                    'signedAt' => $signer->signed_at?->toIso8601String(),
                    'declinedAt' => $signer->declined_at?->toIso8601String(),
                    'declineReason' => $signer->decline_reason,
                    'remindedAt' => $signer->reminded_at?->toIso8601String(),

                    /*
                     * When this person was posted the finished document. The
                     * question somebody asks before pressing "opnieuw
                     * versturen", which is why it is on the line rather than
                     * summarised above it.
                     */
                    'copySentAt' => $signer->copy_sent_at?->toIso8601String(),

                    'state' => match (true) {
                        $signer->hasSigned() => 'signed',
                        $signer->hasDeclined() => 'declined',
                        $signer->opened_at !== null => 'opened',
                        default => 'waiting',
                    },
                ])->all(),
            ],

            /*
             * Everything the screen needs to stop treating this like a contract,
             * or null when it is one.
             *
             * A prop rather than a page of its own, and that is a decision worth
             * defending: a template is the same document with the same boxes and
             * the same author, and a second screen would mean a second copy of
             * the header, the document links and the delete confirmation, all of
             * which would then drift. What actually differs is one panel and the
             * absence of four buttons, which is what this prop switches.
             */
            'template' => $contract->isTemplate() ? $this->templatePanel($contract, $mine) : null,

            'can' => [
                'remind' => $user->can('remind', $contract),
                'cancel' => $user->can('cancel', $contract),
                'update' => $user->can('update', $contract),

                /*
                 * Beside cancel rather than instead of it. Both are usually
                 * true at once on a draft, and the screen offers both, because
                 * they answer different questions: "dit contract moet stoppen"
                 * and "dit had hier nooit moeten staan".
                 */
                'delete' => $user->can('delete', $contract),
                /*
                 * Whether there is anything to send. Not a right of its own —
                 * update() already answered that — but the difference between
                 * a draft waiting for signers and a contract that is out, which
                 * decides whether the screen shows a form or a list.
                 */
                'send' => $user->can('update', $contract)
                    && $contract->status === ContractStatus::Draft
                    /*
                     * Never on a template. It is a draft and will be one
                     * forever, so the status alone would offer the whole
                     * send panel — a form asking for the addresses of people a
                     * mould is being posted to, which is the one thing it must
                     * never be. Sending happens from a contract made out of it;
                     * see InstantiateTemplate.
                     */
                    && ! $contract->isTemplate(),

                /*
                 * Posting the finished document round again. Only once there is
                 * one and only to somebody who may fetch it themselves — the
                 * same right, because mailing a document to the people who
                 * signed it gives away nothing that downloading it does not.
                 */
                'sendCopy' => $user->can('download', $contract)
                    && $contract->signedCopyState() === 'ready'
                    && $contract->signers->contains(fn (ContractSigner $signer): bool => $signer->hasSigned()),

                /*
                 * Sending this same document to somebody else. Offered whatever
                 * the status is — it is the one thing a completed contract
                 * still allows, and the reason it exists — and the source is
                 * asked about because a row whose PDF never arrived has nothing
                 * to copy.
                 */
                'duplicate' => $user->can('duplicate', $contract)
                    && $contract->hasSource()
                    /*
                     * Not on a template either, although the action would
                     * happily copy one. What came back would be an ordinary
                     * contract carrying the template's boxes and none of its
                     * numbering, and without the author's signature — which is
                     * the whole difference between duplicating and using. The
                     * button that means "gebruik dit sjabloon" is
                     * InstantiateTemplate's, and it is not this one.
                     */
                    && ! $contract->isTemplate(),
            ],

            /*
             * The colleagues who can be named as signers, so the author does not
             * have to type an address they already have. Guests are in the list
             * too: being asked to sign something is not privileged access, and a
             * guest is often exactly who a contract is for.
             */
            'members' => $workspace->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                ])->all(),

            'workspaceSlug' => $workspace->slug,
        ]);
    }

    /**
     * What the contract screen shows instead of a status, when the row is a
     * mould rather than a letter.
     *
     * The blockers are the part worth explaining. isReadyToSend answers yes or
     * no, which is the right answer for the API that has to refuse and the wrong
     * one for a person who is standing in front of the thing wondering what is
     * missing — so the same four conditions are asked again here, separately,
     * and handed over as a list of reasons. Kept in one place rather than worked
     * out in the browser: the day a fifth condition is added to isReadyToSend,
     * this list has to gain a line or it starts saying "klaar" about a template
     * the server refuses.
     *
     * @param  ContractSigner|null  $mine  The author's own row, when they still
     *                                     have to sign. Their link, so it is
     *                                     only ever built for them.
     * @return array<string, mixed>
     */
    private function templatePanel(Contract $contract, ?ContractSigner $mine): array
    {
        $author = $contract->templateSigner();

        $blockers = [];

        if (! $contract->hasSource()) {
            $blockers[] = 'document';
        }

        if ($contract->required_signers === null) {
            $blockers[] = 'recipients';
        }

        if ($contract->fields->isEmpty()) {
            $blockers[] = 'fields';
        }

        if ($author !== null && ! $author->hasSigned()) {
            $blockers[] = 'signature';
        }

        return [
            'requiredSigners' => $contract->required_signers,
            'partyCount' => $contract->partyCount(),

            'signsAlong' => $author !== null,
            'authorSigned' => $author?->hasSigned() ?? false,

            /*
             * The author's own way in, which is the ordinary signing page every
             * recipient uses. Deliberately not a shortcut of its own: the
             * signature on a template has to be made under exactly the same
             * conditions and recorded in exactly the same columns as one made by
             * a stranger, or it is worth less than the ones it will be copied
             * beside. See Contract::isSignable, which lets a draft template
             * through for this.
             */
            'signUrl' => $mine?->signUrl(),

            'isReadyToSend' => $contract->isReadyToSend(),
            'blockers' => $blockers,

            /*
             * How many recipients may be asked for, and the least the boxes
             * already drawn will fit in. Both read from the server, because both
             * are rules it enforces — a number typed into the interface would be
             * a promise nobody had agreed to.
             */
            'maxRecipients' => self::MAX_SIGNERS - 1,
            'minRecipients' => app(SetTemplateAuthor::class)->recipientsNeededFor($contract),
        ];
    }

    /**
     * How many people this template will be sent to.
     *
     * Its own endpoint rather than a field in the send panel, because a template
     * has no send panel and never will — the number stands in for the roster
     * that a real contract writes down by name. What it is really setting is how
     * many parties the boxes may be laid out against, which is why the floor is
     * not one but whatever the boxes already drawn need: lowering it past them
     * would leave a signature box belonging to a party the template says does not
     * exist, and isReadyToSend would go on saying "klaar" about it, because it
     * counts fields rather than asking who they are for.
     */
    public function updateTemplate(
        Request $request,
        Workspace $workspace,
        Contract $contract,
        SetTemplateAuthor $templateAuthor,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        // Not a template, not this endpoint. A 404 rather than a flash: nothing
        // draws this form for an ordinary contract, so arriving here means the
        // request did not come from the screen.
        abort_unless($contract->isTemplate(), 404);

        $this->authorize('update', $contract);

        /*
         * One less than a contract's roster, because the author may still put
         * themselves on top of it — see Contract::partyCount. Letting the full
         * twenty through here would allow a template that produces a
         * twenty-one-signer contract SendContract then refuses, which is a
         * refusal nobody would see until the first time somebody tried to use
         * the thing.
         */
        $data = $request->validate([
            'required_signers' => [
                'required',
                'integer',
                'min:'.$templateAuthor->recipientsNeededFor($contract),
                'max:'.(self::MAX_SIGNERS - 1),
            ],
        ]);

        $contract->update(['required_signers' => $data['required_signers']]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('flashes.contract.template_recipients', $data['required_signers']),
        ]);

        return back();
    }

    /**
     * The author putting themselves on the template, or taking themselves off.
     *
     * Apart from the endpoint above because it is a different act with a
     * different rule. Setting the number is an edit, and stops being allowed the
     * moment anybody has signed; this one has to survive that, because taking
     * your own signature off is the only way back to a template you can still
     * change — see ContractPolicy::update, which says so out loud.
     *
     * So the policy asked here is view() rather than update(), and the narrower
     * question that update() would have answered is asked directly: is this
     * still a template, and is it still a draft. Everything else the action
     * guards for itself.
     */
    public function signAlong(
        Request $request,
        Workspace $workspace,
        Contract $contract,
        SetTemplateAuthor $templateAuthor,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);
        abort_unless($contract->isTemplate() && $contract->status === ContractStatus::Draft, 404);

        $this->authorize('view', $contract);

        $data = $request->validate([
            'signs_along' => ['required', 'boolean'],
        ]);

        $changed = $templateAuthor->handle($contract, $request->user(), $data['signs_along']);

        /*
         * Three sentences, because there are three outcomes and the middle one
         * is the one people press by accident: turning it off throws a signature
         * away, and saying "opgeslagen" about that would be the quietest
         * possible way to lose it.
         */
        Inertia::flash('toast', [
            'type' => $changed ? 'success' : 'info',
            'message' => match (true) {
                ! $changed => __('flashes.contract.template_unchanged'),
                $data['signs_along'] => __('flashes.contract.template_signing_along'),
                default => __('flashes.contract.template_not_signing_along'),
            },
        ]);

        return back();
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
                'signers' => $this->parties($contract),

                // Whether the names beside the boxes are people or placeholders,
                // so the editor can say which — see parties().
                'isTemplate' => $contract->isTemplate(),
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
     * Who a box can be handed to, by their place in the queue.
     *
     * Two ways of answering the same question, because a template has nobody to
     * name. Its recipients do not exist yet — inventing placeholder rows for
     * them would put records in contract_signers that look exactly like people
     * who were asked and never answered — so the list is counted out of
     * partyCount instead: the author at zero when they sign along, and
     * "Ontvanger 1" upwards after them.
     *
     * Numbered from the reader's point of view rather than from the column's.
     * The first recipient is "Ontvanger 1" whether they sit at index zero or
     * index one, because which of those it is depends on a switch elsewhere on
     * the screen and is nobody's business while they are drawing boxes.
     *
     * @return list<array{index: int, name: string}>
     */
    private function parties(Contract $contract): array
    {
        if (! $contract->isTemplate()) {
            return array_values($contract->signers->map(fn (ContractSigner $signer): array => [
                'index' => $signer->signing_order,
                'name' => $signer->name,
            ])->all());
        }

        $signsAlong = $contract->templateSigner() !== null;

        $parties = $signsAlong
            ? [['index' => 0, 'name' => __('contracts.template.myself')]]
            : [];

        foreach (range(1, max(1, $contract->required_signers ?? 1)) as $number) {
            $parties[] = [
                'index' => $number - ($signsAlong ? 0 : 1),
                'name' => __('contracts.template.recipient', ['number' => $number]),
            ];
        }

        return $parties;
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

        // partyCount below reads the one signer row a template may have, and
        // asks for it in memory rather than in SQL.
        $contract->loadMissing('signers');

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
             *
             * A template counts its parties instead of listing them: the people
             * it will go to have no rows here, so the rows would say one where
             * the document is laid out for three. See Contract::partyCount.
             */
            'fields.*.signer_index' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.max(0, ($contract->isTemplate()
                    ? $contract->partyCount()
                    : $contract->signers()->count()) - 1),
            ],
        ]);

        $saveFields->handle($contract, $data['fields']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.contract.fields_saved')]);

        return back();
    }

    /**
     * Write down who is going to sign, without asking any of them yet.
     *
     * Its own endpoint because of the order the work is done in. The boxes on a
     * contract belong to people, and the editor cannot offer "wie vult dit in"
     * against a list that does not exist yet — so the list is written first,
     * and only then does signer_index turn from a number into a name.
     *
     * The same rules as sending, minus the deadline and the channel: those are
     * decisions about the invitation rather than about who is on it, and there
     * is no invitation yet.
     */
    public function updateSigners(
        Request $request,
        Workspace $workspace,
        Contract $contract,
        SaveContractSigners $saveSigners,
    ): RedirectResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        /*
         * update rather than a right of its own, and it carries the same two
         * refusals sending does: a contract that is no longer outstanding, and
         * one somebody has already signed. Renaming a signer after they signed
         * would rewrite who agreed to what.
         */
        $this->authorize('update', $contract);

        $signers = $this->validatedSigners($request, $workspace);

        if ($signers instanceof RedirectResponse) {
            return $signers;
        }

        $saveSigners->handle($contract, $signers);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('flashes.contract.signers_saved', count($signers)),
        ]);

        return back();
    }

    /**
     * The list of people, checked.
     *
     * Shared by saving and sending because they are the same list read twice —
     * a rule that held for one and not the other would mean a contract could be
     * laid out against a list it can never be sent to.
     *
     * Hands back a redirect rather than throwing for the one refusal that is
     * not a per-field rule: see below.
     *
     * @return list<array{name: string, email: string, user_id?: int|null}>|RedirectResponse
     */
    private function validatedSigners(Request $request, Workspace $workspace): array|RedirectResponse
    {
        $data = $request->validate([
            'signers' => ['required', 'array', 'min:1', 'max:'.self::MAX_SIGNERS],
            'signers.*.name' => ['required', 'string', 'max:120'],
            'signers.*.email' => ['required', 'email', 'max:255'],

            /*
             * A colleague picked from the workspace rather than an address
             * typed in. Scoped to this workspace in the rule itself: an id from
             * somewhere else must not be storable, and a validator is the only
             * place that refusal reads as a validation error rather than a 403.
             *
             * Null is the ordinary case rather than the exception. Most people
             * asked to sign a contract are customers who have no account here
             * and never will — the token in their link is the whole of their
             * permission, which is why the signing pages sit outside auth.
             */
            'signers.*.user_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
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

        return array_values($data['signers']);
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

        $signers = $this->validatedSigners($request, $workspace);

        if ($signers instanceof RedirectResponse) {
            return $signers;
        }

        $data = $request->validate([
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

        abort_unless($contract->hasSource(), 404);

        $sendContract->handle(
            contract: $contract,
            signers: $signers,
            validForDays: $data['valid_for_days'] ?? null,
            notifyChannelId: $data['notify_channel_id'] ?? null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('flashes.contract.sent', count($signers)),
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
     * to be looked at in a tab — it is the copy that goes into a folder. That
     * is the other way around from source(), where ?download=1 is the
     * exception; here ?inline=1 is, for the reader who only wants to check
     * what the finished document says. Deliberately the same route either
     * way: the file is on the private disk and there is one policy in front
     * of it, and a second way in would be a second thing to get wrong.
     */
    public function download(
        Request $request,
        Workspace $workspace,
        Contract $contract,
    ): BinaryFileResponse {
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

        $inline = $request->boolean('inline');

        $response = response()->file($media->getPath(), [
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($media->file_name).'"',

            // No guessing around the type. See source() for the longer version
            // of why this is not optional on our own origin.
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
