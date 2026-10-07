<?php

namespace App\Actions\UploadLinks;

use App\Actions\Transfers\PruneTransfers;
use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The upload links somebody has out, and what came in through them.
 *
 * The same visibility as the transfer list beside it: a member sees their own,
 * a beheerder the workspace's.
 */
class PresentUploadLinks
{
    /**
     * @param  int|null  $onlyFrom  Whose links, or null for the workspace's.
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace, ?int $onlyFrom): array
    {
        return UploadLink::query()
            ->where('workspace_id', $workspace->id)
            ->when($onlyFrom !== null, fn (Builder $query) => $query->where('created_by', $onlyFrom))
            ->with(['owner', 'notifyChannel', 'submissions.media'])
            ->latest('created_at')
            ->get()
            ->map(fn (UploadLink $link): array => [
                'id' => $link->id,
                // Asked for by name, as on a transfer: a link nobody can read
                // back is a link lost the moment the tab closes.
                'url' => route('upload-links.show', $link->token),
                'title' => $link->title,
                'ownerName' => $link->owner?->name,
                'notifyChannelName' => $link->notifyChannel?->name,
                'isLocked' => $link->isLocked(),
                'uploads' => $link->uploads,
                'maxUploads' => $link->max_uploads,
                'expiresAt' => $link->expires_at,
                'clearedAt' => match (true) {
                    $link->isRevoked() => $link->revoked_at?->copy()->addDays(PruneTransfers::GRACE_DAYS),
                    $link->hasExpired() => $link->expires_at->copy()->addDays(PruneTransfers::GRACE_DAYS),
                    default => null,
                },
                'createdAt' => $link->created_at,
                'state' => $link->state(),
                'submissions' => $link->submissions
                    ->map(fn (UploadLinkSubmission $submission): array => [
                        'id' => $submission->id,
                        'name' => $submission->name,
                        'email' => $submission->email,
                        'note' => $submission->note,
                        'at' => $submission->created_at,
                        'size' => $submission->size(),
                        'downloadAllUrl' => route('chat.upload-links.submissions.download', [$workspace, $link, $submission]),
                        'files' => $submission->files()
                            ->map(fn (Media $media): array => [
                                'id' => $media->id,
                                'name' => $media->file_name,
                                'size' => $media->size,
                                'url' => route('chat.upload-links.files.download', [$workspace, $link, $media->id]),
                            ])->all(),
                    ])->values()->all(),
            ])
            ->all();
    }
}
