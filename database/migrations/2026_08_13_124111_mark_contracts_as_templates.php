<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A contract kept to be sent again, and how many people it expects.
     *
     * Two columns on contracts rather than a table of their own, because a
     * template is not a different thing from a contract — it is a contract with
     * its document normalised, its boxes laid out and its author's signature
     * already on it, which is precisely a contract that has been prepared and
     * not sent. Giving it its own table would mean a second set of fields, a
     * second set of signers and a second renderer, all to say the same thing
     * about the same PDF.
     *
     * is_template is what keeps it out of the way. A template is a Draft
     * forever, so it would otherwise sit in the author's list among the drafts
     * they mean to finish, and every count of outstanding work would include a
     * row that is not waiting on anybody. Everything that lists contracts
     * excludes it; SendContract refuses it outright, because sending the
     * template itself would spend the one signature it exists to keep.
     *
     * required_signers is the number of people the sender fills in — the
     * recipients, not counting the author who pre-signed. It is on the template
     * rather than passed per send because it is a fact about the document: a
     * two-party lease has two parties, and the boxes were drawn on that
     * assumption. An API that could send it to three would be laying a third
     * party's signature box on top of somebody else's. Nullable, because a
     * template being built does not know yet, and a null simply means the
     * template is not ready to be sent.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('status');
            $table->unsignedSmallInteger('required_signers')->nullable()->after('is_template');
        });

        /*
         * Its own index rather than a third column on the existing
         * [workspace_id, status] one. Both overviews now filter on the flag
         * first and only one of them cares about the status: the template list
         * asks for a handful of rows out of a workspace that may hold thousands
         * of contracts, and without this it reads all of them to find them.
         */
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['workspace_id', 'is_template']);
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'is_template']);
            $table->dropColumn(['is_template', 'required_signers']);
        });
    }
};
