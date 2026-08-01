<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Somebody's face, handed out to the people who work with them.
 *
 * Behind a route rather than on a public disk, for the same reason attachments
 * are: this is a photograph of a person, and a guessable URL would put it
 * outside the application entirely. The rule is one step wider than an
 * attachment's — you may see somebody's face if you share a workspace with
 * them, because that is exactly where their name appears next to it.
 */
class AvatarController extends Controller
{
    public function __invoke(Request $request, User $user): StreamedResponse
    {
        $viewer = $request->user();

        /*
         * Sharing a workspace, or being that person. Not simply "signed in":
         * this application can hold several organisations, and somebody in one
         * has no business with the faces in another.
         */
        abort_unless(
            $viewer->is($user) || $this->shareAWorkspace($viewer, $user),
            404,
        );

        $path = $user->avatar_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/webp',
            // A face does not change often and this is requested many times per
            // page. Private, because the answer differs per viewer.
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function shareAWorkspace(User $viewer, User $user): bool
    {
        return $viewer->workspaces()
            ->whereIn('workspaces.id', $user->workspaces()->select('workspaces.id'))
            ->exists();
    }
}
