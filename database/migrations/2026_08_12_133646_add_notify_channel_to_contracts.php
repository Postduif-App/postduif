<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the news about this contract lands when there is nobody to DM.
     *
     * The same column forms carry, added for the same reason and with more
     * force. A DM needs two people, and a contract's signers are almost never
     * members — the whole point of the feature is asking somebody outside to
     * sign. So for most contracts there is no conversation to put "Anna heeft
     * getekend" into, and rather than invent one the author names a channel.
     *
     * It is also a decision worth making out loud rather than defaulting. That
     * a contract came back is not neutral news: the channel it appears in is
     * the list of colleagues who learn that a particular person signed a
     * particular document, and a salary agreement and a delivery note do not
     * belong on the same noticeboard.
     *
     * Null means no chat notification at all, which is a perfectly good answer
     * — the author still gets their mail and, where the signer is a colleague,
     * the DM. See NotifyContractAuthor::destination.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('notify_channel_id')->nullable()->after('message')
                ->constrained('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notify_channel_id');
        });
    }
};
