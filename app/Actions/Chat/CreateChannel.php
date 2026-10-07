<?php

namespace App\Actions\Chat;

use App\Enums\ChannelLayout;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\ChannelSection;
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
     *
     * A section files it in the creator's own sidebar in the same breath, so
     * nobody has to make the channel and then go back to move it. It belongs
     * to the creator alone, which the caller has checked: a section is never
     * somebody else's arrangement to add to.
     */
    public function handle(
        Workspace $workspace,
        User $creator,
        string $name,
        ChannelType $type = ChannelType::Public,
        ?string $topic = null,
        ChannelLayout $layout = ChannelLayout::Chat,
        ?ChannelSection $section = null,
    ): Channel {
        return DB::transaction(function () use ($workspace, $creator, $name, $type, $topic, $layout, $section) {
            $slug = Str::slug($name);

            $channel = Channel::create([
                'workspace_id' => $workspace->id,
                'type' => $type,
                'layout' => $layout,
                'name' => $slug,
                'slug' => $slug,
                'topic' => $topic,
                'created_by' => $creator->id,
            ]);

            $channel->members()->attach($creator->id, ['joined_at' => now()]);

            $section?->channels()->attach($channel->id, ['position' => 0]);

            return $channel;
        });
    }
}
