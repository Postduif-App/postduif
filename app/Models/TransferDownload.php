<?php

namespace App\Models;

use Database\Factories\TransferDownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One handover, recorded.
 *
 * The line between this and the counter on the transfer is worth keeping: the
 * counter is what the limit is enforced against and has to be exactly right,
 * while this is what a person reads afterwards to work out what happened. They
 * are written together so they cannot disagree.
 *
 * @property int $id
 * @property string $transfer_id
 * @property int|null $transfer_recipient_id
 * @property int|null $user_id
 * @property int|null $media_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
#[Fillable(['transfer_id', 'transfer_recipient_id', 'user_id', 'media_id', 'ip', 'user_agent'])]
class TransferDownload extends Model
{
    /** @use HasFactory<TransferDownloadFactory> */
    use HasFactory;

    /** A download happened once and is never edited. */
    public const UPDATED_AT = null;

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /** @return BelongsTo<TransferRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(TransferRecipient::class, 'transfer_recipient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether this was the whole pile at once rather than one file. */
    public function wasWholeArchive(): bool
    {
        return $this->media_id === null;
    }
}
