<?php

namespace App\Http\Controllers;

use App\Actions\Chat\CreateChannel;
use App\Enums\ChannelLayout;
use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelTicketPolicy;
use App\Enums\ChannelType;
use App\Events\ChannelMemberJoined;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChannelController extends Controller
{
    public function store(
        StoreChannelRequest $request,
        Workspace $workspace,
        CreateChannel $createChannel,
    ): RedirectResponse {
        $channel = $createChannel->handle(
            workspace: $workspace,
            creator: $request->user(),
            name: $request->string('name')->value(),
            type: ChannelType::from($request->string('type')->value()),
            topic: $request->string('topic')->trim()->value() ?: null,
            layout: ChannelLayout::from($request->string('layout', ChannelLayout::Chat->value)->value()),
        );

        return redirect()->route('chat.show', [$workspace, $channel]);
    }

    /**
     * Change what the channel is called and how it works: its name, its topic,
     * who may see it, who may post in it, and whether it keeps tickets.
     *
     * The ability is manageSettings, not update: update() on the policy is the
     * admin panel's moderation right and means something else entirely.
     */
    public function update(
        UpdateChannelRequest $request,
        Workspace $workspace,
        Channel $channel,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $type = $request->has('type')
            ? ChannelType::from($request->string('type')->value())
            : $channel->type;

        /*
         * Whoever closes a channel stays inside it.
         *
         * A workspace admin may manage a public channel they never joined, and
         * once it is private, membership is the only way in — so without this
         * they would lock themselves out with the click that made it private,
         * with no way back short of the database.
         */
        if ($type === ChannelType::Private && $channel->type !== $type) {
            $channel->members()->syncWithoutDetaching([
                $request->user()->id => ['joined_at' => now()],
            ]);
        }

        $channel->update([
            ...$request->has('type') ? ['type' => $type] : [],
            ...$request->has('layout') ? [
                'layout' => ChannelLayout::from($request->string('layout')->value()),
            ] : [],
            /*
             * The slug moves with the name. It is what a "#kanaal" in a message
             * resolves against, so leaving it behind would keep old references
             * working under a name nobody sees any more — and would let a later
             * channel claim the name while the address stays taken. Links do not
             * break: a channel is addressed by id, not by slug.
             */
            ...$request->has('name') ? [
                'name' => $name = $request->string('name')->value(),
                'slug' => $name,
            ] : [],
            ...$request->has('topic') ? [
                'topic' => $request->string('topic')->trim()->value() ?: null,
            ] : [],
            'posting_policy' => ChannelPostingPolicy::from(
                $request->string('posting_policy')->value()
            ),
            ...$request->has('replies_open') ? [
                'replies_open' => $request->boolean('replies_open'),
            ] : [],
            ...$request->has('ticket_policy') ? [
                'ticket_policy' => ChannelTicketPolicy::from(
                    $request->string('ticket_policy')->value()
                ),
            ] : [],
            ...$request->has('ticket_announcements') ? [
                'ticket_announcements' => $request->boolean('ticket_announcements'),
            ] : [],
            ...$request->has('ticket_status_announcements') ? [
                'ticket_status_announcements' => $request->boolean('ticket_status_announcements'),
            ] : [],
        ]);

        return back()->with('status', __('flashes.channel.saved'));
    }

    /**
     * Take the channel away, and everything that was ever said in it.
     *
     * A hard delete rather than a soft one. Every table that hangs off a
     * channel — messages, members, tickets, webhooks, links, tags, scheduled
     * messages — cascades on the foreign key, so the row going is the whole
     * thing going. That is the promise the confirmation makes, and a soft
     * delete would quietly break it while looking identical from the outside.
     *
     * Archiving is the reversible option, and it already exists next to this
     * one; whoever reaches for delete has passed it.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('deleteChannel', $channel);

        $name = $channel->name;

        $channel->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.channel.deleted', ['name' => $name]),
        ]);

        /*
         * Not back(): back is the channel that no longer exists, which show()
         * answers with a 404. chat.index picks whichever channel this member
         * was in most recently instead.
         */
        return redirect()->route('chat.index', $workspace);
    }

    /**
     * Put the channel away, or take it back out.
     *
     * The reversible neighbour of destroy(): everything stays readable and
     * nothing can be posted, which ChannelPolicy already reads off archived_at.
     * One method for both directions because it is one decision with a state —
     * two endpoints would let the browser ask for a transition that has already
     * happened.
     *
     * forceFill, because archived_at is deliberately not fillable: it is never
     * set from a form field.
     */
    public function archive(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('archiveChannel', $channel);

        $archiving = $channel->archived_at === null;

        $channel->forceFill(['archived_at' => $archiving ? now() : null])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(
                $archiving ? 'flashes.channel.archived' : 'flashes.channel.reopened',
                ['name' => $channel->name],
            ),
        ]);

        /*
         * An archived channel drops out of the sidebar, so staying on it would
         * leave the member looking at a conversation that is no longer in the
         * list beside it. Reopening lands them back in the channel itself.
         */
        return $archiving
            ? redirect()->route('chat.index', $workspace)
            : back();
    }

    /**
     * Reading a public channel is open to the whole workspace; posting in it
     * means joining first. This is that step.
     */
    public function join(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('join', $channel);

        $already = $channel->members()->whereKey($request->user()->id)->exists();

        $channel->members()->syncWithoutDetaching([
            $request->user()->id => ['joined_at' => now()],
        ]);

        /*
         * Only when it is actually an arrival. syncWithoutDetaching is happy to
         * be called for somebody who is already in, and a welcome message every
         * time they press a button they have already pressed is the reason this
         * is asked first.
         */
        if (! $already) {
            ChannelMemberJoined::dispatch($channel, $request->user());
        }

        return back();
    }
}
