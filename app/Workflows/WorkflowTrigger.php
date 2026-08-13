<?php

namespace App\Workflows;

use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * What sets a workflow off.
 *
 * A trigger is a declaration and nothing more. It says what it needs to be told
 * — which channel, which words — and what it will hand over when it fires. The
 * listening itself lives with whatever does the listening: an event listener, a
 * route, a scheduled command.
 *
 * Keeping it that way is what lets the builder be written once. A screen that
 * had to know that the keyword trigger comes from an event and the webhook one
 * from a URL would grow a branch per trigger, and the seventh trigger would be
 * a rewrite rather than a class.
 */
abstract class WorkflowTrigger
{
    /**
     * How a workflow names this trigger in its trigger_type column.
     *
     * Derived from the class name rather than declared, as with the workspace
     * features: no second list to keep in step. The cost is that renaming the
     * class renames the key, and unlike a feature that key is *stored* — so a
     * rename needs a migration, and that is worth remembering here.
     */
    public static function key(): string
    {
        return Str::kebab(Str::beforeLast(class_basename(static::class), 'Trigger'));
    }

    /** What the trigger is called where somebody picks one. */
    abstract public static function label(): string;

    /** When it fires, in a sentence. */
    abstract public static function description(): string;

    /**
     * What this trigger needs to be told.
     *
     * @return list<WorkflowField>
     */
    public static function fields(): array
    {
        return [];
    }

    /**
     * What a step may reach for, as path => what it holds.
     *
     * This is the contract the whole variable business rests on. It is declared
     * here, next to the trigger that fills it, rather than written out in the
     * builder — a list of offered variables that is maintained apart from the
     * code that produces them is a list that promises things nobody delivers.
     *
     * Paths are given without the "trigger." in front; that prefix is added
     * where the context is built, so a trigger cannot accidentally write
     * outside its own corner of it.
     *
     * @return array<string, string>
     */
    abstract public static function provides(): array;

    /**
     * Whether this trigger may be pointed at a particular workspace at all.
     *
     * Most may. The ones that reach in from outside are the exception — see the
     * webhook trigger, which has no business existing in a workspace that has
     * switched webhooks off, feature flag on workflows or not.
     */
    public static function availableFor(Workspace $workspace): bool
    {
        return true;
    }
}
