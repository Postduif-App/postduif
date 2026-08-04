<?php

namespace App\Actions\Transfers;

use App\Models\Transfer;
use App\Models\TransferDownload;
use App\Models\TransferRecipient;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What somebody has out there, and what it is doing.
 *
 * Lifted out of the controller when the screen moved from workspace settings
 * into the app itself: sending files is ordinary work rather than
 * administration, and the shape of the list did not have to change for that to
 * be true.
 */
class PresentTransfers
{
    /** Enough to answer "is this link doing the rounds", and no more. */
    private const LOG_SHOWN = 10;

    /**
     * The transfers on the list, newest first.
     *
     * The URL is built here and handed over whole. The token is hidden on the
     * model precisely so it never leaves by accident — but a link nobody can
     * read back is a link you lose the moment you close the tab, which is the
     * one thing a shareable link may not be. So it is asked for by name, on a
     * page that only the sender and the beheerder can open.
     *
     * @param  int|null  $onlyFrom  Whose transfers, or null for the workspace's.
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace, ?int $onlyFrom): array
    {
        return Transfer::query()
            ->where('workspace_id', $workspace->id)
            ->when($onlyFrom !== null, fn (Builder $query) => $query->where('created_by', $onlyFrom))
            ->with(['sender', 'media', 'recipients', 'downloadLog.recipient', 'downloadLog.user'])
            ->latest('created_at')
            ->get()
            ->map(fn (Transfer $transfer): array => [
                'id' => $transfer->id,
                'url' => route('transfers.show', $transfer->token),
                'title' => $transfer->title,
                'audience' => $transfer->audience->value,
                'audienceLabel' => $transfer->audience->label(),
                'senderName' => $transfer->sender?->name,
                'fileCount' => $transfer->files()->count(),
                'size' => $transfer->size(),
                'downloads' => $transfer->downloads,
                'lastDownloadedAt' => $transfer->downloadLog->first()?->created_at,
                'maxDownloads' => $transfer->max_downloads,
                'expiresAt' => $transfer->expires_at,

                /*
                 * When the files actually go. Worth saying out loud, because
                 * "verlopen" and "weg" are not the same day: there is a grace
                 * period, and within it a sender can still ask for the date to
                 * be moved rather than upload two gigabytes again. Null while
                 * the transfer is alive — a date that far off would only be
                 * noise.
                 */
                'clearedAt' => match (true) {
                    $transfer->isRevoked() => $transfer->revoked_at?->copy()->addDays(PruneTransfers::GRACE_DAYS),
                    $transfer->hasExpired() => $transfer->expires_at->copy()->addDays(PruneTransfers::GRACE_DAYS),
                    default => null,
                },
                'createdAt' => $transfer->created_at,
                'state' => match (true) {
                    $transfer->isRevoked() => 'revoked',
                    $transfer->hasExpired() => 'expired',
                    $transfer->isExhausted() => 'exhausted',
                    default => 'usable',
                },
                'files' => $transfer->files()
                    ->map(fn (Media $media): string => $media->file_name)
                    ->all(),

                /*
                 * Each address with its own link and its own tally. The link is
                 * handed back in full for the same reason the transfer's is:
                 * the sender has to be able to re-send one to one person
                 * without making the whole transfer again.
                 */
                'recipients' => $transfer->recipients
                    ->map(fn (TransferRecipient $recipient): array => [
                        'id' => $recipient->id,
                        'email' => $recipient->email,
                        'url' => route('transfers.show', $recipient->token),
                        'downloads' => $recipient->downloads,
                        'lastDownloadedAt' => $recipient->last_downloaded_at,
                        'isRevoked' => $recipient->isRevoked(),
                    ])->all(),

                /*
                 * The last handful of handovers, not the whole history. What a
                 * sender is answering with this is "is my link doing the
                 * rounds", and that question is settled by the most recent few
                 * — a full audit trail on a settings screen is a table nobody
                 * reads, holding IP addresses for longer than anybody looked
                 * at them.
                 */
                'downloadLog' => $transfer->downloadLog->take(self::LOG_SHOWN)
                    ->map(fn (TransferDownload $entry): array => [
                        'id' => $entry->id,
                        'at' => $entry->created_at,
                        // Whoever we can name, and null when we cannot — which
                        // is the ordinary case for an open link.
                        // ?? already swallows the null on both sides, so ?->
                        // would be saying the same thing twice.
                        'who' => $entry->recipient->email ?? $entry->user?->name,
                        'ip' => $entry->ip,
                        'wasWholeArchive' => $entry->wasWholeArchive(),
                    ])->values()->all(),
            ])
            ->all();
    }
}
