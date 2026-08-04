<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who a download link works for.
 *
 * The answer to the question that makes a transfer more than a file on a
 * server: a link is a credential anybody can forward, and this is what decides
 * how much a forwarded copy is worth.
 *
 * Defaults to Everyone, because that is what a transfer is usually for —
 * somebody outside who has no account and never will. The narrower settings are
 * the deliberate choice, which is the right way round: a sender who picks
 * "everyone" has said so, rather than discovering it.
 */
enum TransferAudience: string implements HasLabel
{
    case Everyone = 'everyone';
    case WorkspaceMembers = 'workspace-members';
    case NamedRecipients = 'named-recipients';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Everyone => __('enums.transfer-audience.label.Everyone'),
            self::WorkspaceMembers => __('enums.transfer-audience.label.WorkspaceMembers'),
            self::NamedRecipients => __('enums.transfer-audience.label.NamedRecipients'),
        };
    }

    /** What the sender is choosing, in the terms of what can go wrong. */
    public function description(): string
    {
        return match ($this) {
            self::Everyone => __('enums.transfer-audience.description.Everyone'),
            self::WorkspaceMembers => __('enums.transfer-audience.description.WorkspaceMembers'),
            self::NamedRecipients => __('enums.transfer-audience.description.NamedRecipients'),
        };
    }

    /** Whether following this link means being signed in first. */
    public function requiresSignIn(): bool
    {
        return $this === self::WorkspaceMembers;
    }

    /**
     * Whether the transfer's own token opens anything.
     *
     * False for named recipients, and that is what makes the setting mean
     * something: if the shared token still worked beside the personal ones, the
     * list of addresses would be a suggestion rather than a restriction.
     */
    public function opensWithTransferToken(): bool
    {
        return $this !== self::NamedRecipients;
    }
}
