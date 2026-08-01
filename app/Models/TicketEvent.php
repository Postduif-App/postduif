<?php

namespace App\Models;

use App\Enums\TicketEventType;
use Database\Factories\TicketEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that happened to a ticket.
 *
 * This is what makes a status answerable. "Wacht op klant" with no record of
 * who set it and when is a claim nobody can check, and the first thing anyone
 * asks when a ticket has been sitting still is what happened to it.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property TicketEventType $type
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 */
#[Fillable(['ticket_id', 'user_id', 'type', 'payload'])]
class TicketEvent extends Model
{
    /** @use HasFactory<TicketEventFactory> */
    use HasFactory;

    /**
     * There is no updated_at: an event is a fact about a moment, and nothing
     * about it is ever amended.
     */
    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'payload' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TicketEventType::class,
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Who did this, or null when nobody did — a webhook or a scheduled
     * reminder leaves a trace here too.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
