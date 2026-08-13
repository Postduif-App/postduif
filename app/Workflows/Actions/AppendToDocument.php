<?php

namespace App\Workflows\Actions;

use App\Actions\Documents\UpdateDocument;
use App\Enums\WorkflowRecordType;
use App\Features\Documents;
use App\Models\Document;
use App\Models\User;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Add a line to the bottom of a document.
 *
 * The log, in other words: every contract that went out, every ticket that was
 * closed, written into one page people can read from top to bottom. A channel
 * can do that too, but a channel scrolls away and a document does not.
 *
 * Only at the bottom, and only paragraphs. Everything richer a document can
 * hold — tables, code, files — is somebody sitting in the editor arranging
 * things, and a workflow that tried to place a block in the middle of that
 * would be editing around a person who is still typing.
 *
 * The conflict is the real work here, and it is why this is not two lines. A
 * document is saved with optimistic locking: read the version, send it back,
 * and be refused if somebody saved in between. For an append that refusal is
 * almost always wrong — two people adding to the end of a list are not in
 * conflict — so a refusal is met with a fresh read and one more attempt, on the
 * document as it now stands. Twice and no more: past that, something is holding
 * the document open and the honest answer is to fail with a reason.
 */
class AppendToDocument extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly UpdateDocument $updateDocument) {}

    public static function label(): string
    {
        return __('workflows.actions.append-to-document.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.append-to-document.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                'document_id',
                WorkflowRecordType::Document,
                __('workflows.actions.fields.document'),
                __('workflows.actions.fields.document_hint'),
            ),
            WorkflowField::longText(
                'text',
                __('workflows.actions.append-to-document.text.label'),
                __('workflows.actions.append-to-document.text.hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'document.id' => __('workflows.provides.document.id'),
            'document.number' => __('workflows.provides.document.number'),
            'document.title' => __('workflows.provides.document.title'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Documents::class)) {
            throw new RuntimeException(__('workflows.errors.documents_off'));
        }

        $document = $this->document($context);
        $editor = $this->actor($context);

        if ($editor->cannot('update', $document)) {
            throw new RuntimeException(__('workflows.errors.may_not_write_document', [
                'title' => $document->title,
            ]));
        }

        $text = trim((string) $context->setting('text', ''));

        if ($text === '') {
            throw new RuntimeException(__('workflows.errors.empty_document_text'));
        }

        $document = $this->appendWithRetry($document, $editor, $text);

        return [
            'document' => [
                'id' => $document->id,
                'number' => $document->number,
                'title' => $document->title,
            ],
        ];
    }

    /**
     * Write it, and try once more against a document that moved under us.
     *
     * Twice and no more. A second refusal means somebody is sitting in the
     * document saving as fast as we read, and the honest answer then is a
     * failed step with a reason on it rather than a loop nobody can see.
     */
    private function appendWithRetry(Document $document, User $editor, string $text): Document
    {
        try {
            return $this->append($document, $editor, $text);
        } catch (ValidationException) {
            // Somebody saved between the read and the write. The line still
            // belongs at the bottom of whatever the document is now.
        }

        try {
            return $this->append($document, $editor, $text);
        } catch (ValidationException $conflict) {
            throw new RuntimeException(
                __('workflows.errors.document_busy', ['title' => $document->title]),
                previous: $conflict,
            );
        }
    }

    /**
     * One read-and-write, against the document as it stands right now.
     *
     * @throws ValidationException When somebody saved in between.
     */
    private function append(Document $document, User $editor, string $text): Document
    {
        $fresh = $document->fresh();

        if ($fresh === null) {
            throw new RuntimeException(__('workflows.errors.record_not_found', [
                'what' => WorkflowRecordType::Document->label(),
            ]));
        }

        return $this->updateDocument->handle(
            document: $fresh,
            editor: $editor,
            expectedVersion: $fresh->version,
            body: $this->bodyWith($fresh, $text),
            bodyText: trim($fresh->body_text."\n".$text),
        );
    }

    /**
     * The document's blocks with one paragraph added at the end.
     *
     * The shape is the editor's rather than ours — a keyed map of blocks, each
     * carrying its own id and its place in meta.order — so this builds one the
     * same way the editor would and leaves everything else untouched. The order
     * is one past the highest there is, not the count: a document whose blocks
     * were reordered has gaps, and counting would put the new line halfway up.
     *
     * @return array<string, mixed>
     */
    private function bodyWith(Document $document, string $text): array
    {
        $body = $document->body ?? [];

        $order = 0;

        foreach ($body as $block) {
            $order = max($order, ((int) data_get($block, 'meta.order', 0)) + 1);
        }

        $blockId = (string) Str::uuid();

        $body[$blockId] = [
            'id' => $blockId,
            'type' => 'Paragraph',
            'meta' => ['order' => $order, 'depth' => 0],
            'value' => [[
                'id' => (string) Str::uuid(),
                'type' => 'paragraph',
                'children' => [['text' => $text]],
                'props' => ['nodeType' => 'block'],
            ]],
        ];

        return $body;
    }
}
