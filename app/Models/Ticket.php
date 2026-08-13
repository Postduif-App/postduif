<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A piece of work in a channel, tracked apart from the conversation.
 *
 * Deliberately not a message with a status on it. A channel is often a customer
 * channel, and what that customer wants to see at a glance is a list of things
 * still outstanding — not a chat history they have to reconstruct that list
 * from. Keeping the two apart also means a ticket survives everything that
 * happens to messages: editing, deleting, and one day retention.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int $number
 * @property string $title
 * @property string $body
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property int|null $opened_by
 * @property string|null $sender_email
 * @property string|null $sender_name
 * @property string|null $mail_message_id
 * @property int|null $assigned_to
 * @property string|null $source_message_id
 * @property Carbon|null $due_at
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'channel_id', 'number', 'title', 'body', 'status', 'priority', 'opened_by', 'sender_email', 'sender_name', 'mail_message_id', 'assigned_to', 'source_message_id', 'due_at'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The columns carry the same defaults, but a ticket that has just been made
     * would read null for them until it is fetched back — and the board asks
     * both of these the moment a ticket exists.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Normal->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'reminded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Tickets are addressed by their number, not their id.
     *
     * It is what people say out loud and what the board shows, so a URL that
     * disagrees with both would be the one place the numbering does not hold.
     * Scoped route bindings resolve it within the channel, which is where the
     * number is unique enough to be found.
     */
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * Whether this ticket walked in through the letterbox rather than being
     * opened by somebody inside.
     *
     * Asked of the address rather than of a null opener, which is the same
     * question today and would stop being it the moment anything else can open
     * a ticket without a member behind it — a workflow, a scheduled sweep. The
     * address is what this one actually means.
     */
    public function arrivedByEmail(): bool
    {
        return $this->sender_email !== null;
    }

    /**
     * Who opened it, in words, whether that is a member or an address.
     *
     * The name their mail client sent when there is one, and the address when
     * there is not — never both, because a queue reads worse with an address
     * beside every name and the address is one click away on the ticket itself.
     */
    public function openedByName(): ?string
    {
        if (! $this->arrivedByEmail()) {
            return $this->opener?->name;
        }

        return $this->sender_name ?? $this->sender_email;
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The message this ticket was promoted out of, if there was one.
     *
     * withTrashed for the same reason a quote keeps its original: the ticket has
     * to be able to say where it came from even after that message is gone, and
     * a tombstone says more than an empty space.
     *
     * @return BelongsTo<Message, $this>
     */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id')->withTrashed();
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /**
     * The comments as the timeline shows them: withdrawn ones included.
     *
     * A removed comment keeps its place as a tombstone. On a ticket that is not
     * a nicety — a support history where a line can vanish without a trace is
     * one nobody can rely on afterwards. Its words stay behind; only the fact
     * that somebody said something travels out.
     *
     * @return HasMany<TicketComment, $this>
     */
    public function allComments(): HasMany
    {
        return $this->comments()->withTrashed();
    }

    /** @return HasMany<TicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Everything still outstanding, in the sense the channel header counts it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', TicketStatus::openValues());
    }

    /**
     * Tickets in the channels the given user is allowed to see.
     *
     * Leans on the channel rule rather than restating it: a guest's world is
     * made of the channels they were put in, and a ticket is only ever as
     * visible as the channel it sits in.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        /*
         * A subquery rather than whereHas(), and not for style: whereHas takes
         * the relation by name, and a name is a string — so nothing can work
         * out which model the closure's builder is over, and a scope called
         * inside it is a scope on no model in particular.
         *
         * Selecting the ids instead puts the scope where it belongs, on a
         * builder that knows it is over channels. Same rows either way: an
         * EXISTS correlated on channel_id and an IN over the same condition
         * describe the same set.
         */
        $query->whereIn('channel_id', Channel::query()->visibleTo($user)->select('id'));
    }

    /**
     * Board order: the most urgent first, and within one priority the ones that
     * have been waiting longest.
     *
     * Sorted on the enum's weight through a CASE rather than on the column,
     * because "high" sorts after "low" alphabetically and that is the exact
     * opposite of what anyone wants to see on top.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInBoardOrder(Builder $query): void
    {
        /*
         * Ordered by a bound list rather than by interpolated SQL. The values
         * come from an enum and could never carry anything hostile, but a raw
         * string built at runtime is exactly the shape that stops being safe
         * the day somebody makes the priorities configurable.
         */
        $cases = collect(TicketPriority::cases());

        $query
            ->orderByRaw(
                'CASE priority '.str_repeat('WHEN ? THEN ? ', $cases->count()).'END',
                $cases->flatMap(fn (TicketPriority $priority): array => [
                    $priority->value,
                    $priority->weight(),
                ])->all(),
            )
            ->orderBy('id');
    }
}
