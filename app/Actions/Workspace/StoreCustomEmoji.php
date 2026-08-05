<?php

namespace App\Actions\Workspace;

use App\Models\CustomEmoji;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

class StoreCustomEmoji
{
    /**
     * Drawn at about the height of a line of text, so this is generous even on
     * a screen that draws two pixels for every one.
     */
    private const SIZE = 128;

    /**
     * The one format that survives the way in untouched.
     *
     * A GIF is the only kind anybody uploads for the animation, and re-encoding
     * it would hand back a still of the first frame — technically an emoji, and
     * not the one somebody chose. So it is stored as it arrived, which is why
     * the size limit on this route is small: nothing shrinks it later.
     */
    private const KEEPS_ITS_FORMAT = 'image/gif';

    /**
     * Take a picture, shrink it, and give the workspace a name for it.
     *
     * Shrunk on the way in rather than on the way out, the same bargain the
     * avatars make: an emoji appears dozens of times on a screenful of chat,
     * and the version that gets stored is the version that gets drawn.
     *
     * Contained rather than cropped, unlike an avatar. A face in a circle can
     * lose its corners; a picture somebody drew to be a symbol cannot — half a
     * logo is not the logo, and the whole point of a custom emoji is that it is
     * recognisable at the size of a full stop.
     */
    public function handle(Workspace $workspace, string $name, UploadedFile $file, User $author): CustomEmoji
    {
        $keepsFormat = $file->getMimeType() === self::KEEPS_ITS_FORMAT;
        $extension = $keepsFormat ? 'gif' : 'webp';

        $path = 'emoji/workspaces/'.$workspace->id.'/'.Str::random(24).'.'.$extension;

        if (! $keepsFormat) {
            // Converted in place in the upload's temporary file: it gets read
            // once and written once either way.
            Image::load($file->getRealPath())
                ->fit(Fit::Contain, self::SIZE, self::SIZE)
                ->format('webp')
                ->save($file->getRealPath());
        }

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return $workspace->customEmoji()->create([
            'name' => $name,
            'path' => $path,
            'mime' => $keepsFormat ? self::KEEPS_ITS_FORMAT : 'image/webp',
            'created_by' => $author->id,
        ]);
    }

    /**
     * Take one away, file and all.
     *
     * The messages that used it are left exactly as they were. A shortcode that
     * no longer resolves renders as the text somebody typed — ":shipit:" —
     * which is what they wrote, and reads better than a broken image where a
     * word used to be.
     */
    public function remove(CustomEmoji $emoji): void
    {
        Storage::disk('local')->delete($emoji->path);

        $emoji->delete();
    }
}
