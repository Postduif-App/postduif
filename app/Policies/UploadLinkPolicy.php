<?php

namespace App\Policies;

use App\Models\UploadLink;
use App\Models\User;

/**
 * Who gets to see what came in through an upload link, and who may close it.
 *
 * The same two people as for a transfer, and for the same reasons: whoever put
 * the link out, and whoever runs the workspace — so a customer's paperwork is
 * not a workspace-wide noticeboard, and a link handed to the wrong person can
 * be shut by somebody who is not on holiday.
 */
class UploadLinkPolicy
{
    public function view(User $user, UploadLink $uploadLink): bool
    {
        if ($uploadLink->created_by === $user->id) {
            return true;
        }

        return $user->can('manage', $uploadLink->workspace);
    }

    public function delete(User $user, UploadLink $uploadLink): bool
    {
        return $this->view($user, $uploadLink);
    }
}
