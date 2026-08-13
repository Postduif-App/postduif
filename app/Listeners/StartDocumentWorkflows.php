<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Events\DocumentCreated;
use App\Events\DocumentDeleted;
use App\Models\Document;
use App\Models\User;
use App\Models\Workflow;
use App\Workflows\Triggers\DocumentCreatedTrigger;
use App\Workflows\Triggers\DocumentDeletedTrigger;
use App\Workflows\WorkflowTrigger;

/**
 * Set off the workflows that were waiting for a document to appear or go.
 *
 * withTrashed on both, not only on the delete: a document is soft-deleted, and
 * a workflow that starts a second after somebody removed one should still be
 * able to say which document it was talking about. Refusing to describe it
 * would leave a run with a hole in it for no gain — the row is right there.
 */
class StartDocumentWorkflows
{
    public function __construct(private readonly StartMatchingWorkflows $startWorkflows) {}

    public function handleCreated(DocumentCreated $event): void
    {
        $this->start($event->documentId, $event->authorId, DocumentCreatedTrigger::class);
    }

    public function handleDeleted(DocumentDeleted $event): void
    {
        $this->start($event->documentId, $event->actorId, DocumentDeletedTrigger::class);
    }

    /**
     * @param  class-string<WorkflowTrigger>  $trigger
     */
    private function start(int $documentId, int $actorId, string $trigger): void
    {
        $document = Document::withTrashed()
            ->with(['channel.workspace'])
            ->find($documentId);

        if ($document === null) {
            return;
        }

        $actor = User::find($actorId);

        $this->startWorkflows->handle(
            $document->channel->workspace,
            $trigger,
            fn (Workflow $workflow): ?array => $this->matches($workflow, $document)
                ? $this->context($document, $actor)
                : null,
        );
    }

    private function matches(Workflow $workflow, Document $document): bool
    {
        $channelId = $workflow->triggerSetting('channel_id');

        // Loosely, because the id came out of a JSON column where 7 may be "7".
        return blank($channelId)
            || ! ctype_digit((string) $channelId)
            || (int) $channelId === $document->channel_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Document $document, ?User $actor): array
    {
        return [
            /*
             * No url. A document is a tab inside a channel rather than a page
             * of its own, so there is no address to hand anybody — the number
             * and the channel are how people refer to one, and that is what a
             * message written by a workflow can say.
             */
            'document' => [
                'id' => $document->id,
                'number' => $document->number,
                'title' => $document->title,
            ],
            'channel' => ['id' => $document->channel_id, 'name' => $document->channel->name],
            'actor' => ['id' => $actor?->id, 'name' => $actor?->name],
        ];
    }
}
