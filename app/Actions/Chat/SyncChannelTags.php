<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\ChannelTag;
use Illuminate\Support\Facades\DB;

/**
 * Put exactly these labels on a channel.
 *
 * The whole set at once rather than one on and one off: the interface hands
 * over what the channel should carry, and working out the difference here is
 * what keeps the caller from having to.
 *
 * Names go in, not ids. A tag is created by being used — see ChannelTag::claim
 * — so the same call adds a brand new label and reuses one that already exists
 * elsewhere in the workspace, without the caller having to know which it was.
 */
class SyncChannelTags
{
    /**
     * @param  array<int, string|null>  $names
     * @return array<int, ChannelTag>
     */
    public function handle(Channel $channel, array $names): array
    {
        return DB::transaction(function () use ($channel, $names): array {
            $workspace = $channel->workspace;

            $tags = collect($names)
                // Cast rather than typed: an empty field arrives as null,
                // because Laravel converts empty strings on the way in.
                ->map(fn (?string $name): string => trim((string) $name))
                ->filter(fn (string $name): bool => $name !== '')
                // Two spellings of one label are one label: the slug decides,
                // exactly as the unique index does.
                ->uniqueStrict(fn (string $name): string => ChannelTag::slugFor($name))
                ->reject(fn (string $name): bool => ChannelTag::slugFor($name) === '')
                ->map(fn (string $name): ChannelTag => ChannelTag::claim($workspace, $name));

            $channel->tags()->sync($tags->pluck('id'));

            /*
             * Tags nobody uses any more are cleared out here rather than left
             * lying around. They are only ever created by being attached, so
             * one with no channels left is not a label somebody is saving for
             * later — it is the remains of a typo, and it would show up in
             * every picker forever.
             */
            $workspace->channelTags()
                ->whereDoesntHave('channels')
                ->delete();

            return $tags->values()->all();
        });
    }
}
