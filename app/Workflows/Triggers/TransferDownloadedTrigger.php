<?php

namespace App\Workflows\Triggers;

use App\Features\Transfers;
use App\Models\Workspace;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody fetched a transfer that was sent to them.
 *
 * The question the download counter exists to answer — "heeft de klant het
 * opgehaald" — asked without anybody having to go and look.
 *
 * Metadata only, and that is not a gap to be filled in later: what is in a
 * transfer is the sender's and the recipient's business. A workflow learns that
 * it was collected, by which recipient row, and how often, and never what was
 * in it.
 */
class TransferDownloadedTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.transfer-downloaded.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.transfer-downloaded.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'transfer.id' => __('workflows.provides.transfer.id'),
            'transfer.title' => __('workflows.provides.transfer.title'),
            'transfer.downloads' => __('workflows.provides.transfer.downloads'),
            'transfer.expires_at' => __('workflows.provides.transfer.expires_at'),
            'sender.id' => __('workflows.provides.transfer.sender_id'),
            'sender.name' => __('workflows.provides.transfer.sender_name'),
            /*
             * Who collected it, where that is knowable. A transfer sent to
             * anybody with the link is fetched by somebody with no name and no
             * account, and these stay empty rather than guessing.
             */
            'recipient.id' => __('workflows.provides.transfer.recipient_id'),
            'recipient.email' => __('workflows.provides.transfer.recipient_email'),
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),
        ];
    }

    public static function availableFor(Workspace $workspace): bool
    {
        return $workspace->hasFeature(Transfers::class);
    }
}
