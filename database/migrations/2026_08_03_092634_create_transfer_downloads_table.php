<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every time something was actually handed over.
     *
     * The counter on the transfer answers "how many"; this answers "by whom,
     * when, and which file", which is the question a sender asks when a link
     * they gave to one person shows three downloads.
     *
     * Note what is being kept here: an IP address and a user agent are personal
     * data about somebody who may have no account and never agreed to anything.
     * They earn their place by being the only thing that distinguishes "the
     * customer downloaded it three times" from "this link is being passed
     * around" — and they leave with the transfer, which the prune command sees
     * to. Nothing here outlives the thing it is about.
     */
    public function up(): void
    {
        Schema::create('transfer_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('transfer_id')->constrained()->cascadeOnDelete();

            // Which personal link was used, when there was one. Null for an
            // open transfer, where there is nobody to attribute it to.
            $table->foreignId('transfer_recipient_id')->nullable()
                ->constrained()->cascadeOnDelete();

            // The member who fetched it, for a members-only transfer. Null for
            // everybody else, which is most downloads.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Which file, or null for "the lot as one archive". Deliberately
             * not a foreign key: media rows go when the transfer is pruned, and
             * a cascade there would take the log with it a moment before the
             * log is what says the pruning was right.
             */
            $table->unsignedBigInteger('media_id')->nullable();

            // 45 characters holds an IPv6 address written out in full.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // No updated_at: a download happened once and is never edited.
            $table->timestamp('created_at')->nullable();

            $table->index(['transfer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_downloads');
    }
};
