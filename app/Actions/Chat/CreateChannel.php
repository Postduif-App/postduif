<?php

namespace App\Actions\Chat;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateChannel
{
    /**
     * Create a channel and put its creator in it.
     *
     * Membership is not optional here: a channel nobody has joined cannot be
     * posted in, so creating one without joining would hand the member an empty
     * room they are locked out of.
     */
    public function handle(
        Workspace $workspace,
        User $creator,
        string $name,
        ChannelType $type = ChannelType::Public,
        ?string $topic = null,
    ): Channel {
        return DB::transaction(function () use ($workspace, $creator, $name, $type, $topic) {
            $slug = Str::slug($name);

            $channel = Channel::create([
                'workspace_id' => $workspace->id,
                'type' => $type,
                'name' => $slug,
                'slug' => $slug,
                'topic' => $topic,
                'created_by' => $creator->id,
            ]);

            $channel->members()->attach($creator->id, ['joined_at' => now()]);

            return $channel;
        });
    }
}
