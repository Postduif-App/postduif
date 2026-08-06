<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EphemeralNotice;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EphemeralNoticeController extends Controller
{
    /**
     * Dismiss something you were told.
     *
     * Only ever your own, and that is the whole authorisation: a notice belongs
     * to one person by construction, so "is this yours" is the same question as
     * "does this exist for you". Hence 404 rather than 403 — somebody poking at
     * ids should not be able to learn that one of them belongs to a colleague.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        EphemeralNotice $notice,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($notice->user_id === $request->user()->id, 404);

        $notice->delete();

        return back();
    }
}
