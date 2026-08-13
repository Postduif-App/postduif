<?php

namespace App\Models;

use Database\Factories\HuddleRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * What a huddle sounded like, and what was said in it.
 *
 * @property string $id
 * @property int $huddle_id
 * @property int|null $recorded_by
 * @property int|null $duration_seconds
 * @property string|null $transcript
 * @property Carbon|null $transcribed_at
 * @property string|null $transcription_error
 */
#[Fillable(['huddle_id', 'recorded_by', 'duration_seconds'])]
class HuddleRecording extends Model implements HasMedia
{
    /** @use HasFactory<HuddleRecordingFactory> */
    use HasFactory, HasUlids, InteractsWithMedia;

    public const AUDIO = 'audio';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['transcribed_at' => 'datetime'];
    }

    /**
     * On the private disk and served through a policy-guarded route, exactly
     * as message attachments are. A recording of a private channel's meeting is
     * the most sensitive thing this application stores, and a public URL would
     * carry it out of the room the moment anybody forwarded the link.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::AUDIO)->singleFile();
    }

    /** @return BelongsTo<Huddle, $this> */
    public function huddle(): BelongsTo
    {
        return $this->belongsTo(Huddle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Whether the words are in yet. */
    public function isTranscribed(): bool
    {
        return $this->transcribed_at !== null;
    }
}
