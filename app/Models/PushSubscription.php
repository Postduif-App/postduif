<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One browser that has agreed to be interrupted.
 *
 * Belongs to a member and to nothing else. There is no workspace here on
 * purpose: notification preferences are per member in this application, and a
 * browser has no idea which workspace a message came out of anyway. Whether
 * something is worth sending is decided where it is sent.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string|null $user_agent
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'endpoint',
    'public_key',
    'auth_token',
    'content_encoding',
    'user_agent',
])]
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    /**
     * What a browser reports when it says nothing else.
     *
     * Declared here as well as on the column, because the database default only
     * applies on insert — a subscription that has just been made in memory
     * would otherwise read null for the one field the delivery channel has to
     * have before it can encrypt anything.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'content_encoding' => 'aes128gcm',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Note that this browser is still listening.
     *
     * Only ever called after a push service has accepted a message, because
     * that acceptance is the only evidence there is: a browser can be closed,
     * cleared or reinstalled without telling anybody, and the subscription
     * looks exactly the same until something is sent to it.
     */
    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
