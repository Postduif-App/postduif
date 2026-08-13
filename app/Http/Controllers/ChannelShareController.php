<?php

namespace App\Http\Controllers;

use App\Actions\SharedChannels\AddSharedChannelMembers;
use App\Actions\SharedChannels\RespondToChannelShare;
use App\Actions\SharedChannels\RevokeChannelShare;
use App\Actions\SharedChannels\ShareChannelWithWorkspace;
use App\Models\Channel;
use App\Models\ChannelShare;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Opening a channel to another workspace, and everything that follows from it.
 *
 * Two sides of one arrangement live here, and they are not the same people:
 * store() and the host's revoke are the owning workspace's, while update() and
 * members() belong to the workspace that was invited. Kept in one controller
 * because they are four steps of a single conversation between two teams, and
 * splitting them would hide that the middle two are the answer to the first.
 *
 * The actions carry the rules; this class does what a controller should — find
 * the row, ask whether this person may, and turn a refusal from the domain
 * into something the form can show.
 *
 * Two answer shapes, split by what each endpoint changes rather than by which
 * side calls it. The host's three are a list inside a dialog and answer JSON,
 * the way the webhook panel next door does — nothing outside that panel is any
 * different afterwards. The invited workspace's two answer with a redirect,
 * because saying yes makes a channel appear in their sidebar and adding a
 * colleague changes what that person sees: those are page-wide changes, and
 * Inertia's own reload is what keeps the rest of the screen honest about them.
 */
class ChannelShareController extends Controller
{
    public function __construct(
        private readonly ShareChannelWithWorkspace $share,
        private readonly RespondToChannelShare $respond,
        private readonly RevokeChannelShare $revoke,
        private readonly AddSharedChannelMembers $addMembers,
    ) {}

    /**
     * Which workspaces this channel stands open to, and in what state.
     *
     * The host's list, and only theirs: it names other organisations this
     * channel is shared with, which is not something an outside participant in
     * it should be handed. manageSettings is the same right that opens the
     * dialog this list is drawn in.
     */
    public function index(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $this->authorize('manageSettings', $channel);
        abort_unless($channel->workspace_id === $workspace->id, 404);

        return response()->json([
            'shares' => $channel->shares()
                ->with('workspace')
                ->orderBy('id')
                ->get()
                ->map($this->present(...))
                ->values(),
        ]);
    }

    /**
     * The host offers the channel to a workspace, named by its slug.
     *
     * By slug rather than by id, because that is what somebody can be told over
     * the phone — "wij zijn acme-bouw" — and because an id would invite a form
     * that walks the list of every workspace on the installation looking for
     * one to point at.
     */
    public function store(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $this->authorize('manageSettings', $channel);

        /*
         * Ownership, not reachability — the one endpoint under a channel where
         * those two must not be the same question. A channel that merely
         * reaches into this workspace is somebody else's, and letting it be
         * offered onward from here would be a guest subletting the host's room
         * to a third company.
         */
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $validated = $request->validate([
            'workspace' => ['required', 'string', 'exists:workspaces,slug'],
            'can_post' => ['boolean'],
        ]);

        $guest = Workspace::query()->where('slug', $validated['workspace'])->firstOrFail();

        try {
            $share = $this->share->handle($channel, $guest, $request->user(), $validated['can_post'] ?? true);
        } catch (RuntimeException $exception) {
            /*
             * Thrown back at the field somebody filled in rather than as a 500.
             * Every one of these is a thing the person could not have known
             * from the form — that the other workspace does not accept shares,
             * that this is their own workspace — and a validation error is
             * where a form says so.
             */
            throw ValidationException::withMessages([
                'workspace' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'share' => $this->present($share->load('workspace')),
        ], 201);
    }

    /**
     * The invited workspace answers.
     *
     * Under their own workspace in the URL, which is the only workspace they
     * are in — the channel belongs to somebody else, and a route that put it
     * under the host's slug would be asking them to stand somewhere they are
     * not a member.
     */
    public function update(Request $request, Workspace $workspace, ChannelShare $share): RedirectResponse
    {
        abort_unless($share->workspace_id === $workspace->id, 404);
        $this->authorize('manage', $workspace);

        $validated = $request->validate([
            'accepted' => ['required', 'boolean'],
        ]);

        try {
            $this->respond->handle($share, $request->user(), $validated['accepted']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['accepted' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['accepted']
                ? __('flashes.channel-share.accepted')
                : __('flashes.channel-share.declined'),
        ]);

        return back();
    }

    /**
     * Who the invited workspace could still put into the shared channel.
     *
     * Its own people and nobody else, with the ones already in it marked rather
     * than dropped: a picker that silently omitted them would read as "these
     * colleagues are not in this workspace", which is a different and alarming
     * claim.
     */
    public function candidates(Request $request, Workspace $workspace, ChannelShare $share): JsonResponse
    {
        abort_unless($share->workspace_id === $workspace->id, 404);
        $this->authorize('manage', $workspace);

        $inChannel = $share->channel->members()->pluck('users.id')->all();

        return response()->json([
            'candidates' => $workspace->internalMembers()
                ->orderBy('name')
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'username' => $member->username,
                    'avatarUrl' => $member->avatarUrl(),
                    'alreadyIn' => in_array($member->id, $inChannel, true),
                ])
                ->values(),
        ]);
    }

    /**
     * The invited workspace puts its own people in.
     *
     * Never the host's job, and never through the channel's own member button:
     * that one searches the owning workspace's directory. See
     * ChannelPolicy::addMembers, which refuses outsiders for that reason.
     */
    public function members(Request $request, Workspace $workspace, ChannelShare $share): RedirectResponse
    {
        abort_unless($share->workspace_id === $workspace->id, 404);
        $this->authorize('manage', $workspace);

        $validated = $request->validate([
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', 'exists:users,id'],
        ]);

        try {
            $added = $this->addMembers->handle($share, $validated['members']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['members' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('flashes.channel-share.members_added', $added->count(), [
                'count' => $added->count(),
            ]),
        ]);

        return back();
    }

    /**
     * Ending it, from either side.
     *
     * Both are allowed and neither needs the other's agreement, which is the
     * only arrangement that is honest about what this is: two organisations
     * choosing to talk. A host who could not close their own channel, or a
     * guest who could not walk out of somebody else's, would be held in it by
     * the software.
     */
    public function destroy(Request $request, Workspace $workspace, ChannelShare $share): JsonResponse
    {
        abort_unless($this->maySever($request->user(), $workspace, $share), 403);

        $this->revoke->handle($share);

        return response()->json([
            'share' => $this->present($share->load('workspace')),
        ]);
    }

    /**
     * One share as the panel draws it.
     *
     * The state as a word rather than the three timestamps, because that is the
     * question the screen asks — "waar staat dit" — and working it out from
     * three nullable dates is the kind of thing two components would come to
     * disagree about. The moments themselves are not sent: nothing on screen
     * says when, and a date nobody draws is a date that goes stale unnoticed.
     *
     * @return array<string, mixed>
     */
    private function present(ChannelShare $share): array
    {
        return [
            'id' => $share->id,
            'workspace' => [
                'name' => $share->workspace->name,
                'slug' => $share->workspace->slug,
            ],
            'canPost' => $share->can_post,
            'state' => match (true) {
                $share->revoked_at !== null => 'revoked',
                $share->accepted_at !== null => 'accepted',
                $share->declined_at !== null => 'declined',
                default => 'pending',
            },
        ];
    }

    /**
     * Whoever answers for one of the two workspaces in the arrangement.
     *
     * The workspace in the URL decides which of the two questions is asked, so
     * a host cannot reach this by way of the guest's slug or the other way
     * round — the same row, but a different right, and conflating them would
     * let anyone who may manage any workspace end any share.
     */
    private function maySever(User $user, Workspace $workspace, ChannelShare $share): bool
    {
        // The rule itself lives in ChannelSharePolicy, because a workflow step
        // asks the same question and has no URL to work the answer out from.
        return $user->can('sever', [$share, $workspace]);
    }
}
