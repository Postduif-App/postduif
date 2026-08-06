<?php

namespace App\Models;

use App\Enums\ChannelLayout;
use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelTicketPolicy;
use App\Enums\ChannelType;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property ChannelType $type
 * @property ChannelLayout $layout
 * @property ChannelPostingPolicy $posting_policy
 * @property bool $replies_open
 * @property ChannelTicketPolicy $ticket_policy
 * @property bool $ticket_announcements
 * @property bool $ticket_status_announcements
 * @property string|null $name
 * @property string|null $slug
 * @property string|null $topic
 * @property int|null $created_by
 * @property Carbon|null $last_message_at
 * @property Carbon|null $archived_at
 */
#[Fillable(['workspace_id', 'type', 'layout', 'posting_policy', 'replies_open', 'ticket_policy', 'ticket_announcements', 'ticket_status_announcements', 'name', 'slug', 'topic', 'created_by'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'layout' => ChannelLayout::class,
            'posting_policy' => ChannelPostingPolicy::class,
            'replies_open' => 'boolean',
            'ticket_policy' => ChannelTicketPolicy::class,
            'ticket_announcements' => 'boolean',
            'ticket_status_announcements' => 'boolean',
            'last_message_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User, $this, ChannelMembership, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ChannelMembership::class)
            ->withPivot(['last_read_message_id', 'last_read_at', 'last_notified_message_id', 'muted_at', 'muted_until', 'favorited_at', 'joined_at', 'hidden_at', 'hidden_message_id'])
            ->withTimestamps();
    }

    /**
     * This member's own row in the channel, or null when they are not in it.
     *
     * Read off the loaded members rather than queried, so a sidebar drawing
     * forty channels does not ask forty times. Via getAttribute() because the
     * pivot is attached at runtime: asking for it as a property would be asking
     * User for something it does not declare, and the instanceof below is what
     * turns "whatever was attached" back into a type.
     */
    public function membershipFor(User $user): ?ChannelMembership
    {
        $membership = $this->members->firstWhere('id', $user->id)?->getAttribute('pivot');

        return $membership instanceof ChannelMembership ? $membership : null;
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The buttons in the bar above the conversation, in the order they are
     * drawn.
     *
     * @return HasMany<ChannelLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ChannelLink::class)->inOrder();
    }

    /**
     * The huddles held in this channel, the live one and the ones that are
     * over. Newest first, because the only one anybody asks for by hand is the
     * one going on now.
     *
     * @return HasMany<Huddle, $this>
     */
    public function huddles(): HasMany
    {
        return $this->hasMany(Huddle::class)->latest('id');
    }

    /**
     * What individual people were told here and nobody else was.
     *
     * Never fetched whole: the one query that reads these narrows to a single
     * member first — see ChatController — because a notice is only ever for
     * one, and a relation that returns everybody's is a relation somebody will
     * eventually loop over.
     *
     * @return HasMany<EphemeralNotice, $this>
     */
    public function notices(): HasMany
    {
        return $this->hasMany(EphemeralNotice::class);
    }

    /**
     * The labels hung on this channel, alphabetical.
     *
     * @return BelongsToMany<ChannelTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ChannelTag::class)->inOrder();
    }

    /**
     * Messages written for later, said or still waiting.
     *
     * @return HasMany<ScheduledMessage, $this>
     */
    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ScheduledMessage::class);
    }

    /** @return HasMany<Webhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Root messages only; thread replies hang off their parent.
     *
     * @return HasMany<Message, $this>
     */
    public function rootMessages(): HasMany
    {
        return $this->messages()->whereNull('parent_id');
    }

    /**
     * Channels the given user is allowed to see: the ones they are a member of,
     * plus — for anyone who may browse the workspace — its public channels.
     *
     * That second half is what a guest does not get. A guest is in the
     * workspace only for the channels they were put in, so for them membership
     * is the whole answer and a public channel they were left out of does not
     * exist. Written as "member, or public and browsable" rather than the other
     * way around so the membership case reads first: it is the one every role
     * has.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->whereHas('members', fn (Builder $members) => $members->whereKey($user->id))
                ->orWhere(fn (Builder $public) => $public
                    ->where('type', ChannelType::Public)
                    // The workspace by id rather than through the relation: a
                    // relation named by a string leaves the closure's builder
                    // over no model, and browsableBy() is a scope on workspaces.
                    ->whereIn('workspace_id', Workspace::query()
                        ->browsableBy($user)
                        ->select('id')));
        });
    }

    /**
     * Drop the conversations this member has put away.
     *
     * Only until something new is said in one. A DM that was cleared out months
     * ago and then gets a message is not still put away — it is a person
     * writing to you, and a sidebar that hides that would lose the message. So
     * "hidden" means hidden as of the moment it was hidden, and any later
     * activity brings the row back on its own.
     *
     * Direct conversations only. A channel you no longer want to see is one you
     * leave, which is a different thing with a different button.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNotHiddenBy(Builder $query, User $user): void
    {
        /*
         * Against the messages themselves rather than channels.last_message_at:
         * that column is only ever read in this application, never written, so
         * a rule built on it would keep a conversation hidden through every
         * reply.
         *
         * Compared by id — a ULID, so it sorts by the moment the message was
         * made — rather than by hidden_at, which is whole seconds and would
         * count a reply from the same second as older than the click that hid
         * it. Raw because the mark being compared against lives on the pivot of
         * the very subquery doing the comparing, which the builder cannot name.
         */
        $stillQuiet = <<<'SQL'
            not exists (
                select 1 from messages
                where messages.channel_id = channels.id
                  and messages.deleted_at is null
                  and (
                      channel_user.hidden_message_id is null
                      or messages.id > channel_user.hidden_message_id
                  )
            )
            SQL;

        $query->whereNot(fn (Builder $hidden) => $hidden
            ->where('type', ChannelType::Direct)
            ->whereHas('members', fn (Builder $members) => $members
                ->whereKey($user->id)
                ->whereNotNull('channel_user.hidden_at')
                ->whereRaw($stillQuiet)));
    }

    public function isDirect(): bool
    {
        return $this->type === ChannelType::Direct;
    }

    /**
     * Whether this channel reads as a feed rather than as a conversation.
     *
     * A DM never does, whatever the column says — the same guard hasTickets()
     * makes below, and for the same reason: a layout meant for announcements
     * has nothing to say about a conversation between two people.
     */
    public function isFeed(): bool
    {
        return ! $this->isDirect() && $this->layout->isFeed();
    }

    /**
     * Whether this channel keeps tickets at all.
     *
     * A DM never does, whatever the column says: tickets are the shared,
     * outstanding work of a channel, and a two-person conversation has nowhere
     * to hand one over to.
     */
    public function hasTickets(): bool
    {
        return ! $this->isDirect() && $this->ticket_policy->isEnabled();
    }

    /**
     * A DM has no name of its own, so label it with the other participants.
     */
    public function displayNameFor(User $viewer): string
    {
        if (! $this->isDirect()) {
            return (string) $this->name;
        }

        $others = $this->members->reject(fn (User $member) => $member->is($viewer));

        return $others->isEmpty()
            ? $viewer->name.' (jij)'
            : $others->pluck('name')->join(', ');
    }
}
