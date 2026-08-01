<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

class StoreAvatar
{
    /** Every place that draws a face draws it small; this covers the largest. */
    private const SIZE = 256;

    /**
     * Take a picture, square it, and put it away.
     *
     * One action for a member and for a workspace: the storing is the same
     * question — crop, shrink, replace what was there — and only who may ask it
     * differs, which is the controllers' business.
     *
     * Resized on the way in rather than on the way out. An avatar is drawn
     * dozens of times on a page, and serving a four-megabyte original each time
     * is what makes a member list crawl — so the big version simply never gets
     * stored. What is kept is what is shown.
     *
     * Cropped to a square rather than letterboxed: every place it appears is a
     * circle or a square, and a photo with bars down the side looks broken
     * rather than considerate.
     */
    public function handle(User|Workspace $owner, UploadedFile $file): void
    {
        $previous = $owner->avatar_path;

        $folder = $owner instanceof User ? 'users' : 'workspaces';
        $path = 'avatars/'.$folder.'/'.$owner->id.'/'.Str::random(24).'.webp';

        // Converted in place in the upload's temporary file: it is going to be
        // read once and written once either way.
        Image::load($file->getRealPath())
            ->fit(Fit::Crop, self::SIZE, self::SIZE)
            ->format('webp')
            ->save($file->getRealPath());

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        $owner->forceFill(['avatar_path' => $path])->save();

        /*
         * The old file goes only after the new one is stored and pointed at.
         * The other order leaves a member with no face at all if the write
         * fails, which is worse than a stray file.
         */
        if ($previous !== null) {
            Storage::disk('local')->delete($previous);
        }
    }

    /** Take the picture away, file and all. */
    public function remove(User|Workspace $owner): void
    {
        if ($owner->avatar_path === null) {
            return;
        }

        Storage::disk('local')->delete($owner->avatar_path);

        $owner->forceFill(['avatar_path' => null])->save();
    }
}
