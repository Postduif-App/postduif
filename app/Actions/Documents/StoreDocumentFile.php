<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Put one file inside a document.
 *
 * An action rather than three lines in the controller, because two things have
 * to happen together and neither is worth anything alone: the bytes go to the
 * private disk and a row records where they went. Bytes without a row are a
 * file nobody can reach and nothing will ever clean up; a row without bytes is
 * a picture that renders as a broken box.
 */
class StoreDocumentFile
{
    public function handle(Document $document, User $uploader, UploadedFile $file): DocumentFile
    {
        $mimeType = (string) $file->getMimeType();

        /*
         * The workspace has the last word on what may be shared, exactly as it
         * does for a message and for a ticket comment. A workspace that takes
         * no files takes none here either, and one that takes only images is
         * not talked round by a document.
         *
         * Checked here as well as in the form request, and not by mistake: the
         * request judges what the browser sent, this judges what the file turned
         * out to be.
         */
        if (! $document->workspace->acceptsAttachment($mimeType)) {
            throw new RuntimeException('This workspace does not accept files of type '.$mimeType.'.');
        }

        // The same disk the message attachments use — "local" is the private
        // one here, rooted at storage/app/private. See config/filesystems.php.
        $path = $file->store('documents/'.$document->id, 'local');

        if ($path === false) {
            throw new RuntimeException('The file could not be written to the private disk.');
        }

        [$width, $height] = $this->dimensions($file, $mimeType);

        return $document->files()->create([
            'uploaded_by' => $uploader->id,
            'disk' => 'local',
            'path' => $path,
            /*
             * The name it arrived with, kept apart from the random one it is
             * stored under: what a reader downloads should be what the writer
             * uploaded, and a filename is not a safe thing to build a path from.
             */
            'name' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);
    }

    /**
     * How big the picture is, when it is one.
     *
     * Read here rather than trusted from the browser, and stored rather than
     * measured again on every read: the editor needs it to reserve the right
     * amount of space before the bytes arrive, which is what keeps a document
     * from jumping under the reader as its images load.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(UploadedFile $file, string $mimeType): array
    {
        if (! str_starts_with($mimeType, 'image/')) {
            return [null, null];
        }

        // Suppressed rather than guarded: getimagesize() warns on anything it
        // cannot read, and "this svg has no pixel size" is an ordinary answer
        // here rather than a fault worth logging.
        $size = @getimagesize($file->getRealPath());

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }
}
