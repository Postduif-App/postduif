<?php

namespace App\Actions\Workspace;

use App\Actions\Chat\CreateChannel;
use App\Models\Channel;
use App\Models\Workspace;

/**
 * The one channel a workspace starts with.
 *
 * A workspace with no channels is a sidebar with nothing in it and no obvious
 * first move — the create-channel dialog is a right somebody may not hold, and
 * even where they do, an empty room is a poor first impression of a place
 * meant for talking. So every workspace gets one, made at the same moment it
 * is.
 *
 * Public and owned by the owner, because those are the two things that are
 * true of every workspace whoever made it: there is always an owner, and a
 * first channel that started out private would be a room the rest of the
 * workspace cannot see.
 *
 * Idempotent on the slug. It runs from a model event, and a model event is
 * exactly the kind of thing that ends up firing twice.
 */
class CreateHomeChannel
{
    public function __construct(private readonly CreateChannel $createChannel) {}

    public function handle(Workspace $workspace): ?Channel
    {
        $name = __('channels.home.name');

        /*
         * The owner is read through the relation rather than trusted from the
         * attribute: this runs on created, and a workspace saved with an
         * owner_id pointing at nothing has no business also getting a channel
         * whose creator does not exist.
         */
        $owner = $workspace->owner;

        if ($owner === null) {
            return null;
        }

        $existing = $workspace->channels()->where('slug', str()->slug($name))->first();

        if ($existing !== null) {
            return $existing;
        }

        /*
         * Through the same action the create-channel dialog uses, which is the
         * point: it slugs the name, wraps the write in a transaction and puts
         * the creator in the room. A second path that wrote the rows itself
         * would be a second set of those decisions to keep in step.
         */
        return $this->createChannel->handle(
            workspace: $workspace,
            creator: $owner,
            name: $name,
            topic: __('channels.home.topic'),
        );
    }
}
