<?php

namespace App\Models;

use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Something somebody said on a ticket.
 *
 * Kept apart from ticket events, which are what happened to it. A comment can
 * be edited and withdrawn; an event never can. Merging them into one table
 * would mean every query afterwards has to explain which half it means.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property string|null $sender_email
 * @property string|null $sender_name
 * @property string|null $mail_message_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 */
#[Fillable(['ticket_id', 'user_id', 'sender_email', 'sender_name', 'mail_message_id', 'body'])]
class TicketComment extends Model
{
    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /**
     * The files hung on this comment.
     *
     * @return HasMany<TicketCommentAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketCommentAttachment::class)->oldest('id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
