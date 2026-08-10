<?php

namespace App\Enums;

use App\Models\Channel;
use App\Models\User;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a channel keeps documents, and who may write in them.
 *
 * Off by default, the same starting point the tickets take. A document is the
 * channel's shared memory, and most channels do not have one worth keeping —
 * a tab that is empty everywhere teaches people to stop looking at it.
 *
 * Note that this narrows writing, not reading. Whoever can see the channel can
 * read its documents: a document about a channel that some of its members are
 * not allowed to read would be a decision made behind their back.
 */
enum ChannelDocumentPolicy: string implements HasLabel
{
    case Disabled = 'disabled';

    /**
     * Everyone in the channel writes, guests included. The case this setting
     * exists for: a customer channel where the document holds the agreements, and
     * agreements only one side may edit are not agreements.
     */
    case Everyone = 'everyone';

    /**
     * The house's own notes in a channel that happens to have guests in it.
     * They still read along — see the note above — but they do not write.
     */
    case Members = 'members';

    public function isEnabled(): bool
    {
        return $this !== self::Disabled;
    }

    /**
     * Whether this user may create and edit documents here.
     *
     * Membership is checked before this runs; this only narrows it further.
     */
    public function allowsWriting(Channel $channel, User $user): bool
    {
        return match ($this) {
            self::Disabled => false,
            self::Everyone => true,
            self::Members => ! $channel->workspace->isExternal($user),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Disabled => __('enums.channel-document-policy.label.Disabled'),
            self::Everyone => __('enums.channel-document-policy.label.Everyone'),
            self::Members => __('enums.channel-document-policy.label.Members'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Disabled => __('enums.channel-document-policy.description.Disabled'),
            self::Everyone => __('enums.channel-document-policy.description.Everyone'),
            self::Members => __('enums.channel-document-policy.description.Members'),
        };
    }
}
