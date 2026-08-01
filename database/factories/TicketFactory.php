<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Declared before the two closures below, because attributes are
            // expanded in the order they are written and both of them need a
            // channel that has already resolved.
            'channel_id' => Channel::factory(),
            'workspace_id' => fn (array $attributes) => Channel::find((int) $attributes['channel_id'])?->workspace_id,

            // Through the counter rather than a random number: a test that
            // makes three tickets should get #1, #2 and #3, the same way the
            // application hands them out.
            // The id is cast on the way in: find() takes a list as well as one
            // key, and with an unknown type it could come back as a collection.
            'number' => fn (array $attributes) => Workspace::find((int) $attributes['workspace_id'])?->claimTicketNumber() ?? 1,

            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'opened_by' => User::factory(),
        ];
    }

    public function status(TicketStatus $status): static
    {
        return $this->state([
            'status' => $status,
            'closed_at' => $status->isClosed() ? now() : null,
        ]);
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state([
            'assigned_to' => $user->id,
            'status' => TicketStatus::InProgress,
        ]);
    }

    /**
     * A ticket that was promoted out of an existing message.
     *
     * Takes the channel from the message rather than the other way around: a
     * ticket belongs to the channel the message was in, and letting those two
     * drift apart is the one thing that would make the back link nonsense.
     */
    public function promotedFrom(Message $message): static
    {
        return $this->state([
            'source_message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'workspace_id' => $message->workspace_id,
        ]);
    }

    /**
     * A ticket that has been sitting past its due date.
     *
     * Sets the timestamps a reminder looks at without having to let real time
     * pass, which is the only way to test the nagging.
     */
    public function overdue(?DateTimeInterface $dueAt = null): static
    {
        return $this->state([
            'due_at' => $dueAt ?? now()->subDay(),
            'status' => TicketStatus::Open,
        ]);
    }
}
