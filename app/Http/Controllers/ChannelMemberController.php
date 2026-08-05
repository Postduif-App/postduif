<?php

namespace App\Http\Controllers;

use App\Actions\Chat\AddChannelMembers;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChannelMemberController extends Controller
{
    /**
     * Workspace members who could still be added, for the invite picker.
     */
    public function index(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $this->authorizeChannel($request, $workspace, $channel, 'addMembers');

        // A handle is written "@fenna" everywhere it is shown, so somebody who
        // types it that way here must not come up empty; the column holds it
        // without the sign. Same reasoning as the DM picker.
        $terms = $request->string('q')->trim()->ltrim('@')->value();

        $candidates = $workspace->members()
            ->whereNotIn('users.id', $channel->members()->pluck('users.id'))
            ->when($terms !== '', fn ($query) => $query->where(
                fn ($search) => $search
                    ->where('users.name', 'ilike', "%{$terms}%")
                    ->orWhere('users.username', 'ilike', "%{$terms}%")
            ))
            ->orderBy('users.name')
            ->limit(20)
            ->get();

        return response()->json([
            'candidates' => $candidates->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatarUrl' => $user->avatarUrl(),
                // Adding somebody from outside to a channel is a different
                // decision from adding a colleague, so say which one this is
                // before the click rather than after.
                'isGuest' => $channel->workspace->isExternal($user),
                'statusEmoji' => $user->status_emoji,
                'statusText' => $user->status_text,
                'availability' => $user->availability->value,
            ])->values(),
        ]);
    }

    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        AddChannelMembers $addChannelMembers,
    ): RedirectResponse {
        $this->authorizeChannel($request, $workspace, $channel, 'addMembers');

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer'],
        ]);

        $added = $addChannelMembers->handle($channel, $validated['user_ids']);

        /*
         * One key with all three branches rather than a count glued to a word
         * that agrees with it. The nought is a sentence of its own there —
         * nothing happened, which should not read as a tally of nought.
         */
        return back()->with('status', trans_choice('flashes.channel.members_added', $added->count()));
    }

    /**
     * Leave the channel yourself.
     */
    public function destroy(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $workspace, $channel, 'leave');

        $channel->members()->detach($request->user()->id);

        return redirect()->route('chat.index', $workspace);
    }

    /**
     * Remove somebody else from the channel.
     */
    public function remove(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        User $user,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($workspace->hasMember($request->user()), 403);
        $this->authorize('removeMember', [$channel, $user]);

        $channel->members()->detach($user->id);

        return back()->with('status', __('flashes.channel.member_removed', ['name' => $user->name]));
    }

    private function authorizeChannel(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        string $ability,
    ): void {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($workspace->hasMember($request->user()), 403);
        $this->authorize($ability, $channel);
    }
}
