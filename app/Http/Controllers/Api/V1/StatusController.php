<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Users\SetStatus;
use App\Enums\Availability;
use App\Http\Controllers\Controller;
use App\Http\Resources\StatusResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Somebody's own status, over HTTP.
 *
 * The one thing this exists for is the ordinary integration: a calendar that
 * knows about a meeting, a doorbell, a script on a laptop. The web screen and
 * the MCP tool could already do it; what was missing was the plain way.
 *
 * Always the authenticated member's own status — the token identifies a person,
 * so there is no id in the path and no way to reach somebody else's.
 */
class StatusController extends Controller
{
    public function show(Request $request): StatusResource
    {
        return new StatusResource($request->user());
    }

    public function update(Request $request, SetStatus $setStatus): StatusResource
    {
        $validated = $request->validate([
            // The same limits the screen uses. Emoji are multi-byte and often
            // several code points at once, so sixteen characters is generous
            // and still too short for a sentence.
            'emoji' => ['nullable', 'string', 'max:16'],
            'text' => ['nullable', 'string', 'max:100'],
            'availability' => ['required', new Enum(Availability::class)],
        ]);

        /*
         * Through the same action the screen uses, which is the point. SetStatus
         * knows how a manual status relates to a repeating rule — setting one by
         * hand wins over a schedule until the schedule's next turn — and a
         * second path that wrote the columns itself would quietly not.
         */
        $user = $setStatus->handle(
            $request->user(),
            $validated['emoji'] ?? null,
            $validated['text'] ?? null,
            Availability::from($validated['availability']),
        );

        return new StatusResource($user);
    }
}
