<?php

namespace App\Http\Controllers;

use App\Actions\Chat\HideDirectMessage;
use App\Actions\Chat\StartDirectMessage;
use App\Http\Requests\StartDirectMessageRequest;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DirectMessageController extends Controller
{
    /**
     * People this member could start a conversation with, for the picker.
     *
     * Deliberately not ChannelMemberController::index: that one sits behind the
     * addMembers ability and hides people who are already in the channel, both
     * of which are the wrong answer here.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->can('startDirectMessage', $workspace), 403);

        // The picker asks for a name "or @username", so somebody typing the
        // handle the way they see it everywhere else must not come up empty:
        // the column holds "fenna", never "@fenna".
        $terms = $request->string('q')->trim()->ltrim('@')->value();

        $candidates = $workspace->members()
            ->whereKeyNot($user->id)
            ->unless(! $workspace->isExternal($user), $this->sharedChannelsOnly($workspace, $user))
            ->when($terms !== '', fn ($query) => $query->where(
                fn ($search) => $search
                    ->where('users.name', 'ilike', "%{$terms}%")
                    ->orWhere('users.username', 'ilike', "%{$terms}%")
            ))
            ->orderBy('users.name')
            ->limit(20)
            ->get();

        return response()->json([
            'candidates' => $candidates->map(fn (User $candidate): array => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'username' => $candidate->username,
                'avatarUrl' => $candidate->avatarUrl(),
                'isGuest' => $workspace->isExternal($candidate),
                'statusEmoji' => $candidate->status_emoji,
                'statusText' => $candidate->status_text,
                'availability' => $candidate->availability->value,
            ])->values(),
        ]);
    }

    /**
     * Open the conversation — the existing one when there is one, so a second
     * click lands the member back where they already were rather than in a
     * fresh, empty copy of it.
     */
    public function store(
        StartDirectMessageRequest $request,
        Workspace $workspace,
        StartDirectMessage $startDirectMessage,
    ): RedirectResponse {
        $recipient = User::findOrFail($request->integer('user_id'));

        $this->authorize('directMessage', [$workspace, $recipient]);

        $channel = $startDirectMessage->handle($workspace, $request->user(), $recipient);

        return redirect()->route('chat.show', [$workspace, $channel]);
    }

    /**
     * Clear the conversation out of this member's sidebar.
     *
     * Destroy in name only: see HideDirectMessage. The other participant keeps
     * the conversation exactly as it was, which is why this does not go through
     * a policy that talks about deleting — there is nothing here that another
     * member could lose.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        HideDirectMessage $hideDirectMessage,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        abort_unless($channel->isDirect(), 404);

        $user = $request->user();

        abort_unless($channel->members()->whereKey($user->id)->exists(), 403);

        $hideDirectMessage->hide($channel, $user);

        // Back to the workspace rather than to this channel: the member just
        // asked not to see it, and landing on it again would undo that in the
        // same breath — show() reopens whatever DM it is given.
        return redirect()->route('chat.index', $workspace);
    }

    /**
     * Narrow the list to people the member already shares a channel with — the
     * query-side twin of the guest branch in WorkspacePolicy::directMessage().
     * Both have to answer the same, or the picker offers somebody the endpoint
     * then refuses.
     *
     * @return callable(Builder<User>): void
     */
    private function sharedChannelsOnly(Workspace $workspace, User $user): callable
    {
        /** @param Builder<User> $query */
        return function (Builder $query) use ($workspace, $user): void {
            $query->whereHas(
                'channels',
                fn (Builder $channels) => $channels
                    ->where('channels.workspace_id', $workspace->id)
                    ->whereHas('members', fn (Builder $members) => $members->whereKey($user->id))
            );
        };
    }
}
