<?php

namespace App\Models;

use Database\Factories\UploadLinkSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One time somebody sent files in through an upload link.
 *
 * The name is whatever the sender typed, and nothing more: there is no account
 * behind it, so it is a label for the receiver to recognise rather than an
 * identity anybody checked.
 *
 * @property string $id
 * @property string $upload_link_id
 * @property string $name
 * @property string|null $email
 * @property string|null $note
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
#[Fillable(['upload_link_id', 'name', 'email', 'note', 'ip', 'user_agent'])]
class UploadLinkSubmission extends Model implements HasMedia
{
    /** @use HasFactory<UploadLinkSubmissionFactory> */
    use HasFactory, HasUlids, InteractsWithMedia;

    public const UPDATED_AT = null;

    /** The one collection: what was sent in. */
    public const FILES = 'files';

    /**
     * On the private disk, and reached only through a route that asks whether
     * the viewer may see this link's submissions.
     *
     * No conversions, for the reason a transfer has none: any file type comes
     * in here, and handing a stranger's SVG or installer to the image driver
     * is a failed upload rather than a missing thumbnail.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILES);
    }

    /** @return BelongsTo<UploadLink, $this> */
    public function uploadLink(): BelongsTo
    {
        return $this->belongsTo(UploadLink::class);
    }

    /** @return MediaCollection<int, Media> */
    public function files(): MediaCollection
    {
        return $this->getMedia(self::FILES);
    }

    public function size(): int
    {
        return (int) $this->files()->sum('size');
    }
}
