<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A browser that has agreed to be interrupted.
     *
     * One row per browser rather than per person: somebody reads Postduif on a
     * laptop and a phone, and each of those hands out its own endpoint. Sending
     * to one of them is not sending to the member.
     *
     * Deliberately no workspace_id. Notification preferences are per member in
     * this application and not per workspace — see the docblock on
     * NotificationController — and a subscription is a property of the browser,
     * which knows nothing about which workspace caused the message. Deciding
     * which workspace something is worth telling somebody about happens when a
     * notification is sent, not here.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The address the push service will accept a message at, and the
             * identity of the subscription: a browser that re-subscribes hands
             * back the same endpoint, which is what makes storing one an
             * updateOrCreate rather than a growing pile of duplicates.
             *
             * A bounded string rather than text, so the unique index is
             * portable. Postgres would index an unbounded text column happily
             * enough, but MySQL will not without a prefix length or a second
             * hashed column, and neither of those is worth carrying for a value
             * this shape. 512 is far past what the push services actually
             * issue — FCM and Mozilla endpoints run to a couple of hundred
             * characters — and still inside MySQL's 3072-byte index limit at
             * four bytes per character.
             */
            $table->string('endpoint', 512)->unique();

            /*
             * The two halves of the subscription's keys, under the names the
             * application uses rather than the names the browser does: the
             * PushSubscription JSON calls them p256dh and auth, which describe
             * the algorithm and say nothing about what they are for.
             *
             * Both are base64url and short, and both are useless without the
             * endpoint they belong to, so neither is encrypted the way the
             * Pushover key is — that one identifies a person to a third party
             * on its own.
             */
            $table->string('public_key');
            $table->string('auth_token');

            /*
             * How the payload has to be encrypted for this browser. Stored per
             * subscription rather than assumed, because it is the browser's
             * answer and not ours; aes128gcm is what every current browser
             * reports, which makes it the right default and not a certainty.
             */
            $table->string('content_encoding')->default('aes128gcm');

            /*
             * So somebody can tell their own devices apart in the settings
             * screen. Nullable: a subscription that arrived without one is
             * still perfectly able to receive a push, and refusing it over a
             * label would be refusing it over nothing.
             */
            $table->string('user_agent')->nullable();

            /*
             * Stamped when a push is actually accepted, which is the only
             * evidence there is that a browser is still listening. Null until
             * the first one goes out.
             */
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
