<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message as a script that just sent one sees it.
 *
 * Deliberately not the shape the chat screen reads. That one carries what a
 * conversation needs — the author's avatar, the reactions, whether it has been
 * edited — and every field in it is a promise to keep. This is the receipt: the
 * id to refer to it by later, where it landed, and when.
 *
 * @property Message $resource
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * A ULID rather than a number, and a string rather than something
             * to do arithmetic on. It is minted here rather than by the caller
             * — the web client mints its own so it can draw the message before
             * the round trip, which is a problem a script does not have.
             */
            'id' => $this->resource->id,

            'channelId' => $this->resource->channel_id,
            'body' => $this->resource->body,

            // Null unless this went into a thread, in which case it is the
            // message it hangs under.
            'parentId' => $this->resource->parent_id,

            'sentAt' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
