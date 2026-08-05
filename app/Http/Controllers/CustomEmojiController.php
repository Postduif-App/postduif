<?php

namespace App\Http\Controllers;

use App\Models\CustomEmoji;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A workspace's own emoji, handed to the people who work there.
 *
 * Behind a route rather than on a public disk, for the same reason avatars are:
 * these are pictures somebody uploaded into a private workspace, and a
 * guessable URL would put them outside the application. Membership is the whole
 * rule — everyone in a workspace can already see every emoji in it, because
 * they all sit in the same picker.
 */
class CustomEmojiController extends Controller
{
    public function __invoke(Request $request, CustomEmoji $customEmoji): StreamedResponse
    {
        abort_unless($customEmoji->workspace->hasMember($request->user()), 404);

        abort_unless(Storage::disk('local')->exists($customEmoji->path), 404);

        /*
         * Cached hard and privately. The file behind an emoji never changes —
         * the path carries a random name, so a replacement is a different URL —
         * and a screenful of chat asks for the same handful over and over.
         * Private because who may fetch it differs per viewer, so a shared
         * cache must not hold the answer.
         */
        return Storage::disk('local')->response($customEmoji->path, null, [
            'Content-Type' => $customEmoji->mime,
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
