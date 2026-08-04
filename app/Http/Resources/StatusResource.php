<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a member's status looks like from the outside.
 *
 * A resource rather than an array in the controller, per the project's own API
 * guidance: this shape is returned by two endpoints and would otherwise be
 * written twice, which is how the reading and the writing answer differently.
 *
 * @property User $resource
 */
class StatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'emoji' => $this->resource->status_emoji,
            'text' => $this->resource->status_text,
            'availability' => $this->resource->availability->value,

            /*
             * The label in the caller's language rather than only the value.
             * A script that wants to print something has no lang files, and
             * HandleLocale has already worked out which language this request
             * asked for.
             */
            'label' => $this->resource->availability->label(),

            /*
             * Whether this was set by hand or by a repeating rule. Worth
             * saying: a script that sets "in a meeting" and finds it changed an
             * hour later would otherwise have no way to know a schedule did it.
             */
            'isManual' => $this->resource->status_is_manual,
        ];
    }
}
