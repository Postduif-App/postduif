<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Transfers\PresentTransfers;
use App\Enums\TransferAudience;
use App\Features\Transfers;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything this member has sent by link, and what it is doing.
 *
 * Inside the chat shell rather than under workspace settings, where it started.
 * Sending a file to somebody is ordinary work — the same kind of thing as
 * opening a ticket — and putting it behind a settings screen filed it as
 * administration, which is both wrong and two clicks further away than it
 * should be.
 *
 * Visibility is unchanged by the move: a member sees their own, a beheerder
 * sees the workspace's. That split lives in the payload rather than in two
 * screens, because the difference is one column of labels.
 */
class WorkspaceTransferController extends Controller
{
    public function __construct(
        private readonly BuildChatShell $buildChatShell,
        private readonly PresentTransfers $presentTransfers,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403, __('chat.not_a_member'));
        abort_unless($workspace->hasFeature(Transfers::class), 404);

        $isManager = $user->can('manage', $workspace);

        return Inertia::render('chat/transfers', [
            ...$this->buildChatShell->handle($workspace, $user),
            'canSend' => $user->can('createTransfer', $workspace),

            /*
             * The ceilings, so the form can offer what the endpoint will take
             * instead of letting somebody upload two gigabytes and then telling
             * them no.
             */
            'maxTransferKb' => $workspace->max_transfer_kb,
            'maxTransferDays' => $workspace->max_transfer_days,

            // The same set the endpoint accepts, so the form cannot offer a
            // choice the validator then drops.
            'audienceOptions' => collect(TransferAudience::cases())
                ->map(fn (TransferAudience $audience): array => [
                    'value' => $audience->value,
                    'label' => $audience->label(),
                    'hint' => $audience->description(),
                ])->all(),

            // Said out loud so the list can label the rows that are not yours.
            // Without it a beheerder sees a mixed list with no clue why.
            'seesEveryone' => $isManager,
            'transfers' => $this->presentTransfers->handle($workspace, $isManager ? null : $user->id),
        ]);
    }
}
