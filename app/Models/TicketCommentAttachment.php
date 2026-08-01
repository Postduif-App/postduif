<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file somebody hung on a ticket comment.
 *
 * Its own table rather than the media library — see the migration for the id
 * clash that rules the library out here. What it keeps identical is the part
 * that matters: the bytes sit on the private disk and the only way to them asks
 * the ChannelPolicy first.
 *
 * @property int $id
 * @property int $ticket_comment_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 */
#[Fillable(['ticket_comment_id', 'disk', 'path', 'name', 'mime_type', 'size'])]
class TicketCommentAttachment extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * Take the file off the disk as well.
     *
     * A row removed on its own would leave the bytes behind forever, which for
     * something somebody deliberately withdrew is the one outcome that must not
     * happen.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    /** Whether a conversation can show this in place, or only offer it. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** @return BelongsTo<TicketComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'ticket_comment_id');
    }
}
