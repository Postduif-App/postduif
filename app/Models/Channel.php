<?php

namespace App\Models;

use App\Enums\ChannelType;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property ChannelType $type
 * @property string|null $name
 * @property string|null $slug
 * @property string|null $topic
 * @property int|null $created_by
 * @property Carbon|null $last_message_at
 * @property Carbon|null $archived_at
 */
#[Fillable(['workspace_id', 'type', 'name', 'slug', 'topic', 'created_by'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'last_message_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['last_read_message_id', 'muted_at', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Root messages only; thread replies hang off their parent.
     *
     * @return HasMany<Message, $this>
     */
    public function rootMessages(): HasMany
    {
        return $this->messages()->whereNull('parent_id');
    }

    /**
     * Channels the given user is allowed to see: every public channel in the
     * workspace, plus the private channels and DMs they are a member of.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->where('type', ChannelType::Public)
                ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->id));
        });
    }

    public function isDirect(): bool
    {
        return $this->type === ChannelType::Direct;
    }

    /**
     * A DM has no name of its own, so label it with the other participants.
     */
    public function displayNameFor(User $viewer): string
    {
        if (! $this->isDirect()) {
            return (string) $this->name;
        }

        $others = $this->members->reject(fn (User $member) => $member->is($viewer));

        return $others->isEmpty()
            ? $viewer->name.' (jij)'
            : $others->pluck('name')->join(', ');
    }
}
