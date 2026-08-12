<?php

namespace App\Policies;

use App\Enums\WorkspaceAbility;
use App\Models\Channel;
use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Moderation from the admin panel, the same split ChannelPolicy and
     * TicketPolicy make: a platform moderator may work on documents there
     * without that quietly becoming read access to every private channel in
     * the chat.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Seeing the documents of a channel at all.
     *
     * Leans entirely on the channel, the way the ticket board does: a document is
     * exactly as visible as the channel it sits in, and restating that rule
     * here is how the two would eventually disagree.
     *
     * Note what this does *not* narrow by: the channel's document policy decides
     * who may write, never who may read. A document about a channel that some
     * of its members are not allowed to read would be a decision taken behind
     * their back, and a chat application is the wrong place to build that.
     */
    public function viewList(User $user, Channel $channel): bool
    {
        return $channel->hasDocuments()
            && app(ChannelPolicy::class)->view($user, $channel);
    }

    /**
     * A platform moderator gets in, and only here.
     *
     * Not a leak in the rule above: viewList() answers for the chat, where a
     * moderator has no more business in a private channel than anybody else.
     * This answers for the admin panel as well, which they reach only by being
     * a moderator, and which they open because something in a document was
     * reported. Refusing them the document while offering them the button to
     * delete it would be the worst of both.
     */
    public function view(User $user, Document $document): bool
    {
        return $user->isAdmin() || $this->viewList($user, $document->channel);
    }

    /**
     * Starting a new document in a channel.
     *
     * Membership is the floor, the same as posting: reading a public channel is
     * open, adding something lasting to it means you joined. On top of that
     * sits the channel's own document policy, which decides whether guests count.
     */
    public function create(User $user, Channel $channel): bool
    {
        if (! $this->viewList($user, $channel) || $channel->archived_at !== null) {
            return false;
        }

        if (! $channel->members()->whereKey($user->id)->exists()) {
            return false;
        }

        return $channel->document_policy->allowsWriting($channel, $user);
    }

    /**
     * Writing in an existing documents.
     *
     * The same rule as creating one, asked of the document's channel — and
     * deliberately not narrowed to whoever started it. That is the difference
     * between a document and a ticket: a ticket is somebody's report and its
     * author has standing, while a document is the channel's own memory and one
     * that only its original author may correct stops being maintained the week
     * they go on holiday.
     */
    public function update(User $user, Document $document): bool
    {
        return $this->create($user, $document->channel);
    }

    /**
     * Reading the history, and putting an old version back.
     *
     * The same rule as writing, and deliberately not the same as reading. A
     * revision holds text somebody deliberately took out — a name they thought
     * better of, a number that turned out wrong — and showing that to everyone
     * who may read the channel would quietly undo every deletion anybody ever
     * made. Whoever may rewrite the document already has that text in their
     * hands.
     */
    public function viewHistory(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    /**
     * Throwing a document away.
     *
     * Narrower than writing in one, which everybody in the channel may do.
     * Editing leaves a history and a document somebody can put back; deleting
     * makes the whole thing disappear for everyone at once. So it stays with
     * whoever started it, plus whoever runs the workspace.
     *
     * A guest never gets here even for their own: create() is checked first and
     * a channel on the Members policy already refused them.
     */
    public function delete(User $user, Document $document): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $this->update($user, $document)) {
            return false;
        }

        return $document->created_by === $user->id
            || $document->channel->workspace->allows($user, WorkspaceAbility::ManageWorkspace);
    }
}
