<?php

namespace App\Workflows\Triggers;

use App\Features\Documents;
use App\Models\Workspace;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * What the two document triggers share.
 *
 * Two, and the missing third is the interesting one: there is no "document
 * gewijzigd". Saving happens by itself every few seconds of quiet while
 * somebody writes, so a trigger on it would fire on keystrokes — and the
 * workflow built on it would be muted within the afternoon, taking the useful
 * ones with it. What changed inside a document is the document's business; that
 * it exists, and that it is gone, is the channel's.
 *
 * A "gepubliceerd" trigger would be the right third one, and it cannot be
 * written: a document has no published state to fire on. That is a decision
 * about documents rather than about workflows, and it belongs to whoever owns
 * that feature.
 */
abstract class DocumentTrigger extends WorkflowTrigger
{
    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.document.channel.label'),
                __('workflows.triggers.document.channel.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    protected static function documentProvides(): array
    {
        return [
            'document.id' => __('workflows.provides.document.id'),
            'document.number' => __('workflows.provides.document.number'),
            'document.title' => __('workflows.provides.document.title'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'actor.id' => __('workflows.provides.document.actor_id'),
            'actor.name' => __('workflows.provides.document.actor_name'),
        ];
    }

    public static function availableFor(Workspace $workspace): bool
    {
        return $workspace->hasFeature(Documents::class);
    }
}
