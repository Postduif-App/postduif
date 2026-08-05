<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who somebody is, reachable from anywhere their name appears.
 *
 * Scoped to one workspace and to its members, which is the whole of the
 * authorisation: a colleague's timezone and the line they wrote about
 * themselves are ordinary workplace facts, but only inside the place they
 * work. Somebody in another workspace is a 404 rather than a 403 — as far as
 * this member is concerned that person is not here.
 *
 * Inside the chat shell, like the ticket list and the inbox: it is a place you
 * step into for a moment and step back out of, and a shell of its own would
 * lose the sidebar you came from.
 */
class WorkspaceMemberProfileController extends Controller
{
    public function __construct(private readonly BuildChatShell $buildChatShell) {}

    public function show(Request $request, Workspace $workspace, User $member): Response
    {
        $viewer = $request->user();

        abort_unless($workspace->hasMember($viewer), 403, __('chat.not_a_member'));
        abort_unless($workspace->hasMember($member), 404);

        $role = $workspace->roleFor($member);

        /*
         * Re-read through the relation rather than trusting the route model:
         * $member came from the URL and carries no pivot, and "membership"
         * is the accessor Workspace::members() names for this side.
         */
        $membership = $workspace->members()->whereKey($member->id)->sole();

        return Inertia::render('chat/member', [
            ...$this->buildChatShell->handle($workspace, $viewer),
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'username' => $member->username,
                'avatarUrl' => $member->avatarUrl(),
                'bio' => $member->bio,

                /*
                 * The zone and the time it is there, worked out here rather than
                 * in the browser. The reader's machine knows its own clock, not
                 * somebody else's zone — and the one question this answers is
                 * "can I message them now".
                 */
                'timezone' => $member->timezone,
                'localTime' => now($member->timezone)->format('H:i'),

                'status' => [
                    'emoji' => $member->status_emoji,
                    'text' => $member->status_text,
                    'availability' => $member->availability->value,
                    'label' => $member->availability->label(),
                ],

                // Said out loud so a guest is recognisable as one. Which
                // channels they are in is deliberately not here: that is the
                // member list's business, per channel, and a profile page
                // listing them would tell a guest where everybody else works.
                'role' => $role?->key,
                'roleLabel' => $role?->name,
                'joinedAt' => $membership->membership->joined_at?->toIso8601String(),
                'isYou' => $member->is($viewer),
            ],
        ]);
    }
}
