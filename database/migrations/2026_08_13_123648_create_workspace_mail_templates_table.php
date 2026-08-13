<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a workspace wants its contract mails to say.
     *
     * A table rather than columns on workspace_mail_settings, which is where
     * this nearly went. Two kinds of mail times two languages times four fields
     * is sixteen columns today, and the count grows with every language the
     * application learns — HandleLocale::SUPPORTED is the thing that decides,
     * and a schema that has to be migrated when a translation is added is a
     * schema that will be one language behind.
     *
     * Every text column is nullable and means the same thing when it is: use
     * the platform's own sentence. That is the invariant the whole feature
     * hangs on, the same one workspace_mail_settings carries — no row, an empty
     * row, and a workspace that never opened the screen all send exactly the
     * mail this application sent before any of this existed.
     *
     * One row per workspace per kind per language, said by the unique index
     * rather than by whoever writes it. There is no version history here: "de
     * tekst" is a single answer, and updateOrCreate against a unique key is
     * what keeps it one.
     */
    public function up(): void
    {
        Schema::create('workspace_mail_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('kind');
            /*
             * Short, because it holds a language tag and not a language. Five
             * characters is 'pt_BR' with nothing to spare, which is the longest
             * shape HandleLocale would ever accept.
             */
            $table->string('locale', 5);

            $table->string('subject')->nullable();
            $table->string('heading')->nullable();
            /*
             * Text rather than string. This is the one field somebody writes
             * paragraphs in, and a 255-character limit on a mail body is a
             * limit nobody would guess was there until they hit it mid-sentence.
             */
            $table->text('body')->nullable();
            $table->string('button_label')->nullable();

            $table->timestamps();

            $table->unique(['workspace_id', 'kind', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_mail_templates');
    }
};
