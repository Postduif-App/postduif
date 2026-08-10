<?php

namespace App\Actions\Documents;

use App\Actions\Chat\CensorBlockedWords;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use stdClass;

class PresentDocument
{
    public function __construct(
        private readonly CensorBlockedWords $censorBlockedWords,
    ) {}

    /**
     * Blocklists already fetched, keyed by workspace. A list draws every document
     * in a channel from one workspace, and without this each of them asks the
     * database for the same words.
     *
     * @var array<int, array<int, string>>
     */
    private array $blockedWords = [];

    /**
     * A document as the list shows it.
     *
     * Without the document, and that is the whole reason this is separate from
     * handle(). A document body is a JSON tree that can run to hundreds of
     * kilobytes; a channel with a dozen of them would ship all of it to draw a
     * list of titles.
     *
     * @return array<string, mixed>
     */
    public function summary(Document $document): array
    {
        $document->loadMissing(['creator', 'editor']);

        return [
            'id' => $document->id,
            'number' => $document->number,
            'title' => $this->censor($document->workspace_id, $document->title),
            'excerpt' => $this->censor($document->workspace_id, $document->excerpt()),
            'createdBy' => $document->creator?->name,
            /*
             * Who touched it last, falling back to who started it. A list that
             * says "bijgewerkt door —" for every document nobody has edited yet
             * reads as broken rather than as new.
             */
            'updatedBy' => ($document->editor ?? $document->creator)?->name,
            'createdAt' => $document->created_at?->toIso8601String(),
            'updatedAt' => $document->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A document as the editor opens it.
     *
     * @return array<string, mixed>
     */
    public function handle(Document $document, User $viewer): array
    {
        return [
            ...$this->summary($document),

            /*
             * The document, uncensored — and deliberately so.
             *
             * The blocklist works on plain strings, and this is a tree of
             * blocks whose shape belongs to the editor. Walking it to censor
             * text nodes would be a second, worse implementation of the
             * editor's own serialiser, and getting it subtly wrong would not
             * fail loudly: it would hand the editor a document it can no longer
             * parse. The title and the excerpt are censored, which is what
             * appears in lists and links; a document is written by members of the
             * channel rather than shouted into it, which is the case the
             * blocklist is built for.
             */
            'body' => $this->document($document),

            /*
             * What the browser must send back when it saves. This is the whole
             * conflict mechanism as far as the client is concerned: hold on to
             * it, return it, and be told if it is stale.
             */
            'version' => $document->version,

            'canEdit' => $viewer->can('update', $document),
            'canDelete' => $viewer->can('delete', $document),
        ];
    }

    /**
     * @param  iterable<int, Document>  $documents
     * @return array<int, array<string, mixed>>
     */
    public function list(iterable $documents): array
    {
        $rows = [];

        foreach ($documents as $document) {
            $rows[] = $this->summary($document);
        }

        return $rows;
    }

    /**
     * The document, as something JSON will write as an object.
     *
     * PHP has one array type and json_encode writes an empty one as `[]`, but
     * the editor reads its value as a map of block id to block and refuses a
     * list outright — "Initial value is not valid. Should be an object with
     * blocks. You passed: []".
     *
     * The model already guards the way in; this is the way out, which is a
     * separate journey through json_decode and back. An empty stdClass is the
     * only value PHP has that encodes to `{}`.
     *
     * @return stdClass|array<string, mixed>
     */
    private function document(Document $document): stdClass|array
    {
        return $document->body === [] ? new stdClass : $document->body;
    }

    /**
     * The workspace's blocklist, fetched once per workspace per request.
     *
     * On the way out rather than on the way in, the same as messages and
     * tickets: a word added to the list today also disappears from a document
     * titled last month.
     */
    private function censor(int $workspaceId, string $text): string
    {
        $words = $this->blockedWords[$workspaceId] ??= Workspace::query()
            ->whereKey($workspaceId)
            ->firstOrFail()
            ->blocked_words;

        return $this->censorBlockedWords->handle($text, $words);
    }
}
