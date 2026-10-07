<?php

namespace App\Actions\UploadLinks;

use App\Models\Channel;
use App\Models\UploadLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;

class CreateUploadLink
{
    /**
     * Put a link out that somebody can send files in through.
     *
     * @param  int  $validForDays  Counted from now. Never null: a link anybody
     *                             may fill with gigabytes must stop at some point.
     * @param  int|null  $maxUploads  How many times it may be used, or null for
     *                                as often as the sender likes.
     * @param  Channel|null  $notifyChannel  Where the news goes when something
     *                                       comes in, besides the owner's own mail.
     */
    public function handle(
        Workspace $workspace,
        User $owner,
        string $title,
        int $validForDays,
        ?string $message = null,
        ?int $maxUploads = null,
        ?string $password = null,
        ?Channel $notifyChannel = null,
    ): UploadLink {
        return UploadLink::create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'notify_channel_id' => $notifyChannel?->id,
            'token' => UploadLink::freshToken(),
            'title' => $title,
            'message' => $message,
            // Hashed here, as a transfer's is: one place decides this is a
            // password rather than a value to show back.
            'password' => $password === null ? null : Hash::make($password),
            'expires_at' => now()->addDays($validForDays),
            'max_uploads' => $maxUploads,
        ]);
    }
}
