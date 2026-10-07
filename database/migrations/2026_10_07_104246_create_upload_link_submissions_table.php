<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One time somebody sent files in through an upload link.
     *
     * Its own row rather than media hung straight on the link, because "who
     * sent what, when" is the question the receiver asks: three people at the
     * same customer may each send their part, and a flat pile of files would
     * leave nobody able to tell whose was whose.
     *
     * Keyed by ULID because the files hang here, and the media table keys
     * model_id as character(26).
     *
     * The name, address and IP are personal data about somebody with no
     * account. They leave with the link — the prune command sees to it.
     */
    public function up(): void
    {
        Schema::create('upload_link_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('upload_link_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->text('note')->nullable();

            // 45 characters holds an IPv6 address written out in full.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // No updated_at: a submission is sent once and never edited.
            $table->timestamp('created_at')->nullable();

            $table->index(['upload_link_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_link_submissions');
    }
};
