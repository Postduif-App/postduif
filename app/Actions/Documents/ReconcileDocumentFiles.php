<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentFile;

/**
 * Throw away the files a document no longer mentions.
 *
 * A document is not a message. A message arrives complete — its files come with
 * it and leave with it — while a document is written over months, and the
 * uploading happens before the saving. Somebody drops in four screenshots,
 * decides two of them said nothing, and closes the tab: those two are on the
 * disk with nothing pointing at them, and nobody will ever come looking.
 *
 * So the body is the authority on which files belong to the document, and this
 * runs on every save to make the disk agree with it.
 */
class ReconcileDocumentFiles
{
    /**
     * How long a file may go unmentioned before it counts as abandoned.
     *
     * Not zero, and this is the whole subtlety of the class. A file is uploaded
     * the moment it is dropped, and the block that names it is only saved when
     * autosave next fires — seconds later, or minutes if the writer is
     * thinking. Reconciling without this window would delete every file between
     * the upload and the next save, which is to say all of them.
     */
    private const GRACE_MINUTES = 60;

    public function handle(Document $document): void
    {
        $referenced = $this->referencedIds($document->body);

        $document->files()
            ->when($referenced !== [], fn ($query) => $query->whereNotIn('id', $referenced))
            ->where('created_at', '<', now()->subMinutes(self::GRACE_MINUTES))
            /*
             * One at a time through the model rather than a mass delete: the
             * bytes are removed by the deleted() hook on DocumentFile, and a
             * query-builder delete does not fire it. That would leave exactly
             * the orphaned files this class exists to prevent, only harder to
             * find because the rows would be gone too.
             */
            ->each(fn (DocumentFile $file) => $file->delete());
    }

    /**
     * The address a file block points at, as this application writes it.
     *
     * Matched rather than parsed: the id is the last segment of our own route,
     * and nothing else in a document body looks like this.
     */
    private const URL_PATTERN = '#/documents/\d+/files/(\d+)#';

    /**
     * Which file ids the document points at.
     *
     * Two ways of asking, and both are needed.
     *
     * The `fileId` and `id` props are what the editor writes when it inserts a
     * block, and an id is the half that survives a route rename or a moved
     * domain. But which props survive a round trip is the plugin's business,
     * not ours — @yoopta/image keeps `id` for the storage provider, and a
     * future version is free to drop what it does not recognise.
     *
     * So the src is read as well. A picture that is visibly in the document has
     * a URL in the document, whatever happened to the props around it, and
     * deleting a file that is still on screen is the one mistake here that
     * cannot be undone.
     *
     * Recursive because the shape below a block belongs to whichever plugin
     * made it, and this should not have to know one plugin's nesting from
     * another's.
     *
     * @param  array<mixed>  $body
     * @return array<int, int>
     */
    private function referencedIds(array $body): array
    {
        $ids = [];

        array_walk_recursive($body, function (mixed $value, int|string $key) use (&$ids): void {
            if (($key === 'fileId' || $key === 'id') && is_numeric($value)) {
                $ids[] = (int) $value;
            }

            if (is_string($value) && preg_match_all(self::URL_PATTERN, $value, $matches) > 0) {
                foreach ($matches[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
        });

        return array_values(array_unique($ids));
    }
}
