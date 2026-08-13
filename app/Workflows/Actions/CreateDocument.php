<?php

namespace App\Workflows\Actions;

use App\Actions\Documents\CreateDocument as StartDocument;
use App\Features\Documents;
use App\Models\Document;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Start a document in a channel.
 *
 * The workflow that pays for itself: a new project channel gets its own notes
 * page the moment it exists, named after whatever the trigger was about, and
 * nobody has to remember. Through the ordinary CreateDocument, so the number is
 * claimed the same way and the channel is told the same way.
 *
 * It opens empty, as one made by hand does. Filling it in is the next step —
 * see append-to-document — which is also how a workflow puts a standard opening
 * in it without this action having to know what a document is made of.
 */
class CreateDocument extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly StartDocument $startDocument) {}

    public static function label(): string
    {
        return __('workflows.actions.create-document.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.create-document.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
            WorkflowField::text(
                'title',
                __('workflows.actions.create-document.title.label'),
                __('workflows.actions.create-document.title.hint'),
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
            'channel.id' => __('workflows.provides.channel.id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Documents::class)) {
            throw new RuntimeException(__('workflows.errors.documents_off'));
        }

        $channel = $this->channel($context);
        $author = $this->actor($context);

        /*
         * The channel's own document rule as well as the workspace feature: a
         * channel can keep documents switched off, and DocumentPolicy::create
         * weighs that together with whether this member is in it.
         */
        if ($author->cannot('create', [Document::class, $channel])) {
            throw new RuntimeException(__('workflows.errors.may_not_create_document', [
                'channel' => (string) $channel->name,
            ]));
        }

        $title = trim((string) $context->setting('title', ''));

        if ($title === '') {
            throw new RuntimeException(__('workflows.errors.empty_document_title'));
        }

        $document = $this->startDocument->handle($channel, $author, $title);

        return [
            'document' => [
                'id' => $document->id,
                'number' => $document->number,
                'title' => $document->title,
            ],
            'channel' => ['id' => $channel->id],
        ];
    }
}
