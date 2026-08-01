<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SetStatus;
use App\Enums\Availability;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Your own status: what you are up to, and whether you want to be reached.
 *
 * A single endpoint rather than a settings screen. Setting a status is
 * something you do mid-conversation, from the menu you are already in — sending
 * somebody to a separate page for it is how a feature ends up unused.
 */
class StatusController extends Controller
{
    public function update(Request $request, SetStatus $setStatus): RedirectResponse
    {
        $validated = $request->validate([
            // Emoji are multi-byte and often several code points at once, so the
            // limit is generous in characters and still short enough that no
            // sentence fits through here.
            'status_emoji' => ['nullable', 'string', 'max:16'],
            'status_text' => ['nullable', 'string', 'max:100'],
            'availability' => ['required', new Enum(Availability::class)],
        ]);

        $setStatus->handle(
            $request->user(),
            $validated['status_emoji'] ?? null,
            $validated['status_text'] ?? null,
            Availability::from($validated['availability']),
        );

        return back();
    }
}
