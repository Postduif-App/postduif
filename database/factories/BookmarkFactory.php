<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmark>
 */
class BookmarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'message_id' => Message::factory(),
            /*
             * Taken off the message rather than made on its own, so the two can
             * never point at different channels — the saved list is scoped on
             * this column, and a row where they disagree would show up in the
             * wrong place or nowhere at all.
             */
            'channel_id' => fn (array $attributes): int => Message::query()
                ->whereKey($attributes['message_id'])
                ->value('channel_id'),
        ];
    }
}
