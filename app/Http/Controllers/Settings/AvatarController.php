<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\StoreAvatar;
use App\Enums\AttachmentType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Setting your own face, and taking it away again.
 *
 * Only ever your own: an avatar is how somebody chooses to appear, and nobody
 * else gets to choose it for them — not even whoever runs the workspace.
 */
class AvatarController extends Controller
{
    public function __construct(private readonly StoreAvatar $storeAvatar) {}

    /** Two megabytes. It is stored as a 128-pixel square either way. */
    private const MAX_KB = 2048;

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'image',
                'max:'.self::MAX_KB,
                // The image group, so an SVG cannot come in — the same list the
                // attachments use, and for the same reason: an SVG is a script
                // in a costume.
                'mimetypes:'.implode(',', AttachmentType::Images->mimeTypes()),
            ],
        ], [
            'avatar.mimetypes' => 'Kies een gewone afbeelding: png, jpg, gif of webp.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->storeAvatar->handle($user, $request->file('avatar'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto opgeslagen.']);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->storeAvatar->remove($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto verwijderd.']);

        return back();
    }
}
