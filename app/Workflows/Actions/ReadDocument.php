<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowRecordType;
use App\Features\Documents;
use App\Features\WorkspaceFeature;

/**
 * Read a document again.
 *
 * The quietest of the four — a document's only moving fact is its title — and
 * here anyway, because leaving out exactly one kind of record reads as an
 * oversight rather than a decision. A workflow that appends to a document and
 * then says what it is called wants the name it has now, not the one it was
 * given.
 */
class ReadDocument extends ReadRecord
{
    public static function label(): string
    {
        return __('workflows.actions.read-document.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.read-document.description');
    }

    protected static function type(): WorkflowRecordType
    {
        return WorkflowRecordType::Document;
    }

    /** @return class-string<WorkspaceFeature> */
    protected static function feature(): string
    {
        return Documents::class;
    }

    protected static function fieldLabel(): string
    {
        return __('workflows.actions.fields.document');
    }

    protected static function fieldHint(): string
    {
        return __('workflows.actions.fields.document_hint');
    }
}
