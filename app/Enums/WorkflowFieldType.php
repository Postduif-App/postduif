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

    /** A form of this workspace, chosen from a list. */
    case Form = 'form';

    case Emoji = 'emoji';
    case Number = 'number';

    /** A handful of words, entered one at a time. */
    case Words = 'words';

    /** One of a fixed set the field names itself. */
    case Choice = 'choice';

    /**
     * Something this workspace has — a ticket today, a contract tomorrow.
     *
     * Which kind is not part of the type: the field says that, through
     * WorkflowRecordType. One control in the builder for all of them, rather
     * than a type and a picker per feature.
     */
    case Record = 'record';

    /**
     * Whether a value of this type can hold {{ ... }}.
     *
     * The free-text ones, the channel, the record and the person.
     *
     * The channel was excluded on the grounds that a variable could point a
     * step at a channel nobody chose, including one in another workspace. The
     * first half of that is the whole point — "antwoord in het kanaal waar het
     * vandaan kwam" cannot be written any other way — and the second half was
     * never the picker's to prevent: every step resolves its channel through
     * FindsTargets, which looks only inside the workflow's own workspace and
     * refuses what it does not find there. A variable naming somebody else's
     * channel finds nothing, exactly as a typed-in id already did.
     *
     * The form picker stays out, and now for a reason of its own rather than by
     * association: a form id is a ULID with no name to fall back on, so a
     * variable there can only ever be an opaque string that either matches or
     * silently does not — and "watch whichever form this names" is a way of
     * listening to another workspace's answers if the scoping is ever loosened.
     *
     * The number is excluded for the plainer reason that "{{ trigger.x }}
     * minutes" cannot be validated when it is saved, which is the one moment
     * somebody is there to be told.
     *
     * The record is in, on the channel's reasoning rather than the form's.
     * "Sluit het ticket dat stap twee aanmaakte" is a sentence with no other
     * way of being written, and what makes it safe is the same thing that makes
     * a channel variable safe: the lookup only ever searches the workflow's own
     * workspace and refuses what it does not find there. Leaving the field
     * empty says the same thing about the trigger's own record, which is the
     * commoner case and needs no variable at all.
     *
     * The person is in, for the channel's reasons and one of its own. "Stuur de
     * aanvrager van dit contract een bericht" names somebody who is different
     * on every run, so no list can hold them; the same goes for the person a
     * ticket is assigned to and the colleague who just clocked in. What makes
     * it safe is again the lookup rather than the picker: FindsTargets searches
     * $context->workspace()->members() and nothing else, so a variable holding
     * an address from outside finds nobody and the step stops. That is the
     * whole security of it — a person field is a way to send somebody a
     * message, and members() is what keeps that inside the workspace.
     */
    public function acceptsVariables(): bool
    {
        return $this === self::Text
            || $this === self::LongText
            || $this === self::Channel
            || $this === self::Member
            || $this === self::Record;
    }
}
