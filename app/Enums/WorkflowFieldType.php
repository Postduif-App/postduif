<?php

namespace App\Enums;

/**
 * What kind of answer a trigger or an action is asking for.
 *
 * The list is short on purpose. Every type here is a control the builder has to
 * be able to draw, so adding one is a promise about the screen as much as about
 * the data — and a type that only one action ever uses is a control nobody
 * recognises.
 */
enum WorkflowFieldType: string
{
    case Text = 'text';
    case LongText = 'long-text';

    /** A channel in this workspace, chosen from a list. */
    case Channel = 'channel';

    /** Somebody in this workspace. */
    case Member = 'member';

    case Emoji = 'emoji';
    case Number = 'number';

    /** A handful of words, entered one at a time. */
    case Words = 'words';

    /** One of a fixed set the field names itself. */
    case Choice = 'choice';

    /**
     * Whether a value of this type can hold {{ ... }}.
     *
     * Only the free-text ones. A channel picker hands back an id, and letting a
     * variable through there would mean a run could point a step at a channel
     * nobody chose — including one in another workspace.
     *
     * The number is excluded for the plainer reason that "{{ trigger.x }}
     * minutes" cannot be validated when it is saved, which is the one moment
     * somebody is there to be told.
     */
    public function acceptsVariables(): bool
    {
        return $this === self::Text || $this === self::LongText;
    }
}
