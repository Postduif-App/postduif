<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelShare;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelShare>
 */
class ChannelShareFactory extends Factory
{
    /**
     * A live arrangement by default, rather than the unanswered invitation a
     * share truthfully starts as.
     *
     * The default is chosen for what a test is usually setting up: almost
     * everything worth asserting about a shared channel — who may read it, who
     * may post, what the sidebar shows — only happens once both sides have
     * agreed, and a default that granted nothing would put ->accepted() on top
     * of nearly every call. The honest starting state is one word away, in
     * pending() below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            // A workspace of its own, never the channel's: the model refuses a
            // share with the workspace that owns the channel, and a factory
            // that reached for the channel's workspace here would make that
            // refusal look like a broken factory.
            'workspace_id' => Workspace::factory(),
            'invited_by' => User::factory(),
            'accepted_by' => User::factory(),
            'can_post' => true,
            'accepted_at' => now(),
        ];
    }

    /** Offered, and the other workspace has not answered yet. */
    public function pending(): self
    {
        return $this->state(fn (): array => [
            'accepted_by' => null,
            'accepted_at' => null,
        ]);
    }

    /** The other workspace said no. */
    public function declined(): self
    {
        return $this->state(fn (): array => [
            'accepted_by' => null,
            'accepted_at' => null,
            'declined_at' => now(),
        ]);
    }

    /** It was live and one of the two sides ended it. */
    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    /** Live, but the other side may only read. */
    public function readOnly(): self
    {
        return $this->state(fn (): array => ['can_post' => false]);
    }
}
