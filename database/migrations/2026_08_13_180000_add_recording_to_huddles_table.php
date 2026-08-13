<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this huddle is being recorded right now, and by whom.
     *
     * On the huddle rather than derived from huddle_recordings, because those
     * rows only exist once the file has been uploaded — which is when the
     * recording is over. The one moment this has to be knowable is exactly the
     * moment there is nothing to derive it from.
     *
     * Two columns rather than a boolean: the indicator names the person
     * ("Sanne neemt dit gesprek op"), and a red dot that says nothing about who
     * is holding the microphone is a worse notice than no dot at all.
     *
     * Cleared when the recording stops, when the recorder leaves, and when the
     * huddle is swept — see LeaveHuddle and SweepStaleHuddles. A stuck "wordt
     * opgenomen" would be the one kind of wrong this feature cannot afford.
     */
    public function up(): void
    {
        Schema::table('huddles', function (Blueprint $table) {
            $table->foreignId('recording_by')->nullable()->after('started_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('recording_started_at')->nullable()->after('recording_by');
        });
    }

    public function down(): void
    {
        Schema::table('huddles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recording_by');
            $table->dropColumn('recording_started_at');
        });
    }
};
