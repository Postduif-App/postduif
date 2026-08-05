<?php

namespace Database\Factories;

use App\Models\CustomEmoji;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomEmoji>
 */
class CustomEmojiFactory extends Factory
{
    protected $model = CustomEmoji::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => Str::lower(fake()->unique()->word()),
            /*
             * A path with no file behind it. Enough for everything that only
             * needs the emoji to exist — the picker, a reaction, a message that
             * mentions it — and a test about the picture itself uploads one
             * through the endpoint, which is where a real file comes from.
             */
            'path' => 'emoji/'.Str::random(24).'.webp',
            'mime' => 'image/webp',
        ];
    }
}
