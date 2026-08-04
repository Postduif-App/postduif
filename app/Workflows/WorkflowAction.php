<?php

namespace App\Workflows;

use Illuminate\Support\Str;

/**
 * One thing a workflow can do.
 *
 * The same shape as a trigger — key, label, fields — with a run() on the end.
 * Actions are resolved out of the container, so one may ask for SendMessage or
 * ToggleReaction in its constructor: everything here goes through the actions
 * the rest of the application already uses, and a second path for sending a
 * message would be a second place for mentions and unread counts to be
 * forgotten.
 */
abstract class WorkflowAction
{
    /**
     * How a step names this action in its action_type column.
     *
     * As with a trigger, this is stored, so renaming the class is a migration
     * rather than a rename.
     */
    public static function key(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /** What the action is called where somebody picks one. */
    abstract public static function label(): string;

    /** What it does, in a sentence. */
    abstract public static function description(): string;

    /**
     * What this action needs to be told.
     *
     * @return list<WorkflowField>
     */
    public static function fields(): array
    {
        return [];
    }

    /**
     * What this action leaves behind for later steps, as path => what it holds.
     *
     * Relative to the step, so "channel.id" is read as
     * {{ steps.2.channel.id }} once the runner has filed it under the step's
     * position. Positions rather than names because a step has no name — see
     * the builder, where they are a row rather than a list of labels.
     *
     * @return array<string, string>
     */
    public static function provides(): array
    {
        return [];
    }

    /**
     * Do the thing, and hand back whatever a later step might want.
     *
     * Returning null means "nothing worth remembering", which is the ordinary
     * case: most of these post something and are done. Throwing is how an
     * action says the run cannot sensibly continue — the runner turns that into
     * a failed step and stops, rather than carrying on into steps that were
     * written on the assumption this one worked.
     *
     * @return array<string, mixed>|null
     */
    abstract public function run(WorkflowStepContext $context): ?array;
}
