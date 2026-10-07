<?php

namespace App\Actions\UploadLinks;

use App\Features\Transfers;
use App\Models\UploadLink;
use Illuminate\Http\Request;

class ResolveUploadLink
{
    /**
     * The upload link this token stands for, or a 404.
     *
     * One answer for a token nobody recognises and for a workspace that has
     * switched the feature off, for the reason PublicTransferController gives:
     * "this exists but not for you" is itself an answer, and a beheerder who
     * switched it off believes the door is shut.
     */
    public function handle(string $token): UploadLink
    {
        $link = UploadLink::with(['workspace', 'owner'])
            ->where('token', $token)
            ->first();

        abort_if($link === null, 404);
        abort_unless($link->workspace->hasFeature(Transfers::class), 404);

        return $link;
    }

    /** Whether this browser has already answered the password for this link. */
    public function isUnlocked(Request $request, UploadLink $link): bool
    {
        return ! $link->isLocked()
            || $request->session()->get($link->unlockedSessionKey()) === true;
    }
}
