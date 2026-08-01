<?php

use App\Enums\AttachmentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ten megabytes: big enough for a screenshot or a slide deck. */
    private const DEFAULT_MAX_KB = 10240;

    /**
     * What a workspace allows people to send along with a message.
     *
     * Three separate questions rather than one, because they are turned at
     * different moments: whether files are wanted here at all, which kinds, and
     * how large. A workspace that serves customers may well want screenshots
     * but no archives, and that is a different decision from the size cap.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('uploads_enabled')->default(true)->after('channel_creation');

            /*
             * jsonb, not json. Postgres has no equality operator for json, so a
             * single json column is enough to break any "select distinct
             * table.*" — which is what Filament builds for a relation manager.
             */
            $table->jsonb('allowed_attachment_types')
                ->default(json_encode(AttachmentType::defaults()))
                ->after('uploads_enabled');

            // Kilobytes, the unit the validator speaks, so nothing has to
            // convert on the way to the rule that enforces it.
            $table->unsignedInteger('max_attachment_kb')
                ->default(self::DEFAULT_MAX_KB)
                ->after('allowed_attachment_types');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'uploads_enabled',
                'allowed_attachment_types',
                'max_attachment_kb',
            ]);
        });
    }
};
