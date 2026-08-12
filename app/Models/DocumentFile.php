<?php

namespace App\Models;

use Database\Factories\DocumentFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file that lives inside a document.
 *
 * Its own table rather than the media library — see the migration for the id
 * clash that rules the library out here, the same one the ticket comments ran
 * into. What it keeps identical is the part that matters: the bytes sit on the
 * private disk and the only way to them asks the DocumentPolicy first.
 *
 * @property int $id
 * @property int $document_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Document $document
 */
#[Fillable(['document_id', 'uploaded_by', 'disk', 'path', 'name', 'mime_type', 'size', 'width', 'height'])]
class DocumentFile extends Model
{
    /** @use HasFactory<DocumentFileFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
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
        static::deleted(function (self $file): void {
            Storage::disk($file->disk)->delete($file->path);
        });
    }

    /** Whether a document can show this in place, or only offer it. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * The address the editor stores in the document.
     *
     * Built here rather than saved into the body, so a route rename or a moved
     * domain does not leave every document pointing at nothing. The body keeps
     * the id; this turns it back into a URL each time the document is read.
     */
    public function url(): string
    {
        // loadMissing rather than a plain read: a document being presented has
        // these two already, and one being handed back from an upload has
        // neither. Callers that draw a list should eager load them all the same.
        $this->loadMissing('document.workspace', 'document.channel');

        return route('chat.documents.files.show', [
            $this->document->workspace,
            $this->document->channel,
            $this->document,
            $this,
        ]);
    }
}
