<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowRecordType;
use App\Features\Polls;
use App\Features\WorkspaceFeature;

/**
 * Read a poll again.
 *
 * A tally is the fastest-moving thing a workflow can be pointed at: every vote
 * changes leading_option and top_votes, so "wacht tot morgen en meld wie voor
 * ligt" is only truthful with this step in front of the message.
 */
class ReadPoll extends ReadRecord
{
    public static function label(): string
    {
        return __('workflows.actions.read-poll.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.read-poll.description');
    }

    protected static function type(): WorkflowRecordType
    {
        return WorkflowRecordType::Poll;
    }

    /** @return class-string<WorkspaceFeature> */
    protected static function feature(): string
    {
        return Polls::class;
    }

    protected static function fieldLabel(): string
    {
        return __('workflows.actions.fields.poll');
    }

    protected static function fieldHint(): string
    {
        return __('workflows.actions.fields.poll_hint');
    }
}
