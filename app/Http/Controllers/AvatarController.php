<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
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
    /**
     * A workspace's own logo.
     *
     * Membership is the rule, one step narrower than a member's face: sharing
     * some other workspace with somebody says nothing about whether you belong
     * to this one.
     */
    public function workspace(Request $request, Workspace $workspace): StreamedResponse
    {
        abort_unless($workspace->hasMember($request->user()), 404);

        return $this->stream($workspace->avatar_path);
    }

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

        return $this->stream($user->avatar_path);
    }

    /**
     * The stored square, or nothing.
     *
     * Cached briefly and privately: a face does not change often and this is
     * requested many times per page, but the answer differs per viewer so a
     * shared cache must not hold it.
     */
    private function stream(?string $path): StreamedResponse
    {
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/webp',
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
