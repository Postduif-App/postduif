<?php

namespace Database\Factories;

use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketEvent>
 */
class TicketEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'type' => TicketEventType::Created,
            'payload' => [],
        ];
    }

    public function statusChange(TicketStatus $from, TicketStatus $to): static
    {
        return $this->state([
            'type' => TicketEventType::StatusChanged,
            'payload' => ['from' => $from->value, 'to' => $to->value],
        ]);
    }

    /**
     * An event with nobody behind it, the way a webhook or a scheduled reminder
     * leaves one.
     */
    public function bySystem(): static
    {
        return $this->state(['user_id' => null]);
    }
}
