<?php

namespace App\Workflows\Triggers;

use App\Features\Transfers;
use App\Models\Workspace;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody sent files in through an upload link.
 *
 * "De klant heeft de stukken aangeleverd" is the moment somebody has been
 * waiting for, and a workspace can now open a ticket on it instead of checking
 * the list every morning.
 *
 * Metadata only, like TransferDownloadedTrigger: how many files and how much,
 * from whom by their own account, and never what is in them.
 */
class UploadLinkSubmittedTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.upload-link-submitted.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.upload-link-submitted.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'upload_link.id' => __('workflows.provides.upload_link.id'),
            'upload_link.title' => __('workflows.provides.upload_link.title'),
            'upload_link.uploads' => __('workflows.provides.upload_link.uploads'),
            'upload_link.expires_at' => __('workflows.provides.upload_link.expires_at'),
            'submission.id' => __('workflows.provides.upload_link.submission_id'),
            'submission.files' => __('workflows.provides.upload_link.files'),
            'submission.size' => __('workflows.provides.upload_link.size'),
            /*
             * What the sender typed about themselves. Nobody checked it — there
             * is no account behind an upload link — so a workflow should treat
             * it as a label, not as somebody it can look up.
             */
            'uploader.name' => __('workflows.provides.upload_link.uploader_name'),
            'uploader.email' => __('workflows.provides.upload_link.uploader_email'),
            'owner.id' => __('workflows.provides.upload_link.owner_id'),
            'owner.name' => __('workflows.provides.upload_link.owner_name'),
        ];
    }

    public static function availableFor(Workspace $workspace): bool
    {
        return $workspace->hasFeature(Transfers::class);
    }
}
