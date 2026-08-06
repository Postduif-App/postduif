<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;

/**
 * Whether this application has been set up yet.
 *
 * One definition, asked from three places — the middleware that sends a
 * visitor to the onboarding screen, the middleware that guards that screen,
 * and the action behind it. Three copies of "are there any workspaces" is
 * three chances for the screen to be reachable a moment longer than the door
 * it opens.
 *
 * Note that it asks about accounts as well as workspaces, and that it has to.
 * "No workspaces" alone would be a hole rather than a check: a platform whose
 * last workspace was removed would put the screen back up, and the next
 * stranger to open the address would be handed moderator rights over
 * everybody's account and every message they ever sent.
 *
 * So this is the narrow reading — nothing here at all — and it is the honest
 * one. An installation where somebody has signed up but made no workspace is
 * not uninstalled; it is an installation whose first member is about to press
 * the button on /app/nieuw. What it does lack is a moderator, and the way to
 * appoint one there is the way it has always been: `php artisan user:promote`,
 * from the server, by whoever put it there. See PromoteUser.
 */
class Installation
{
    /**
     * Deliberately not cached. It runs once per web request and stops at the
     * first query, which on any platform that has ever been used answers from
     * an index and returns a single row. A cached flag would have to be
     * invalidated from every place that makes or removes an account or a
     * workspace, which is a far wider surface than the query it saves.
     */
    public function pending(): bool
    {
        return ! User::query()->exists()
            && ! Workspace::query()->exists();
    }
}
