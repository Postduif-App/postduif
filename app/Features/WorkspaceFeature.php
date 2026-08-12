<?php

namespace App\Features;

use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * A part of the product a workspace may switch off.
 *
 * Not everything belongs here. A workspace setting that carries more than a
 * yes or no — which file types are allowed, how large they may be — stays a
 * column on the workspace, because a flag cannot hold a list. What lives here
 * are the plain switches: a whole capability is either offered or it is not.
 *
 * These are classes rather than closures because a flag has to answer more
 * than "is it on" for the beheerscherm to be usable: it needs a name a person
 * recognises and a sentence saying what turning it off costs them.
 */
abstract class WorkspaceFeature
{
    /**
     * Every feature a workspace can be asked about, in the order the beheerder
     * should see them.
     *
     * Listed rather than discovered from the directory: the order is a choice,
     * and a class appearing in a menu the moment a file is created is the kind
     * of surprise that makes a beheerscherm untrustworthy.
     *
     * @var array<int, class-string<WorkspaceFeature>>
     */
    public const ALL = [
        ScheduledMessages::class,
        SavedMessages::class,
        MessageForwarding::class,
        MessageBoard::class,
        Tickets::class,
        Documents::class,
        Timeclock::class,
        Polls::class,
        Forms::class,
        Huddles::class,
        Webhooks::class,
        Workflows::class,
        InviteLinks::class,
        Transfers::class,
        SecretRequests::class,
        Contracts::class,
        AiAccess::class,
    ];

    /**
     * How a route asks for this feature: middleware('feature:tickets').
     *
     * Derived from the class name rather than declared, so there is no second
     * list to keep in step with the first. The cost is that renaming a class
     * renames the key — which is fine, because the key only ever appears in
     * route files that a rename has to touch anyway.
     */
    public static function key(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * The feature a route asked for, or null when nothing answers to that name.
     *
     * @return class-string<WorkspaceFeature>|null
     */
    public static function fromKey(string $key): ?string
    {
        foreach (self::ALL as $feature) {
            if ($feature::key() === $key) {
                return $feature;
            }
        }

        return null;
    }

    /** What the feature is called where a person reads it. */
    abstract public static function label(): string;

    /** What a workspace loses by switching it off. */
    abstract public static function description(): string;

    /**
     * What holds for a workspace that never said anything about this feature.
     *
     * Most things are on: a new workspace should get the whole product, and
     * switching parts off is the deliberate act. The exception is anything
     * that lets something outside the workspace look in.
     */
    public static function default(): bool
    {
        return true;
    }

    /**
     * Pennant asks this once per workspace and remembers the answer, which is
     * why the stored value — not this method — is what a beheerder edits.
     */
    public function resolve(Workspace $workspace): bool
    {
        return static::default();
    }
}
