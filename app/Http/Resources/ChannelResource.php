<?php

namespace App\Http\Resources;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel as something outside this application needs to know it.
 *
 * Enough to pick one and to know whether posting in it will work. canPost is
 * the field that earns its place: reading a public channel is open and writing
 * means having joined, and a client that is told so up front does not have to
 * discover it by being refused.
 *
 * @property Channel $resource
 */
class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,

            /*
             * What it is called to this member, which for a direct message is
             * the other person's name — the row itself has no name at all.
             */
            'label' => $this->resource->displayNameFor($request->user()),

            'type' => $this->resource->type->value,
            'topic' => $this->resource->topic,
            'workspace' => $this->resource->workspace?->name,
            'canPost' => $request->user()->can('post', $this->resource),
        ];
    }
}
