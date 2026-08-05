<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A form somebody fills in, and what came back.
     *
     * Keyed by ULID like Poll, Transfer and SecretRequest: it lands in a
     * conversation and its id travels in a URL.
     *
     * The shape to notice is on form_answers. An answer keeps its own copy of
     * the question and of the field key, rather than reading them through the
     * field it belongs to. That is the same decision bot_name made on messages:
     * a label somebody edits next month must not rewrite what a person answered
     * last month, and a field deleted after the fact must not take its answers'
     * meaning with it. The foreign key stays for grouping and for reading the
     * type back; the words are copied.
     */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // The author keeps the form alive after they leave, the way a poll
            // outlives its asker. What does stop is the DM — see SendFormAnswers.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            /*
             * Where the answers land when there is nobody to send a DM to.
             *
             * A form shared as a public link can be filled in by somebody with
             * no account, and a DM needs two people. Rather than inventing a
             * conversation for that case, the author names a channel — an
             * explicit choice about who gets to read the answers, which is the
             * kind of choice a holiday request deserves to be asked about.
             */
            $table->foreignId('notify_channel_id')->nullable()->constrained('channels')->nullOnDelete();

            /*
             * The public link, or null when there is none.
             *
             * A column rather than a flag: withdrawing the link has to make the
             * old URL dead, and only replacing the secret can do that. Unique
             * because it is what the public route looks a form up by.
             */
            $table->string('share_token', 64)->nullable()->unique();

            // Whether the same person may send it in more than once. A holiday
            // request wants this; a one-off sign-up sheet does not.
            $table->boolean('allows_multiple_submissions')->default(false);

            /*
             * When it stops taking answers on its own, and when somebody
             * stopped it by hand. Both nullable, both meaning closed — kept
             * apart so the card can say which it was, exactly as a poll does.
             */
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('form_id')->constrained()->cascadeOnDelete();

            /*
             * The name a workflow refers to this field by: {{ trigger.answers.reden }}.
             *
             * Derived from the label once, at creation, and then left alone.
             * The label is prose somebody rewrites; a variable that changed
             * with it would break every workflow that read it, quietly.
             */
            $table->string('key', 60);

            $table->string('type', 20);
            $table->string('label');
            $table->string('hint')->nullable();
            $table->boolean('required')->default(true);

            // The choices, for the field types that have any. Empty for the rest.
            $table->json('options')->default('[]');

            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['form_id', 'key']);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_id')->constrained()->cascadeOnDelete();

            // Null means somebody came in over the public link without an
            // account. Nullable on delete too: a leaver's answers stay, the way
            // their messages do.
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            // Which door they came through. Worth recording separately from
            // submitted_by being null, because a member may also follow the
            // public link, and the answers screen should say so.
            $table->boolean('via_link')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index(['form_id', 'created_at']);
        });

        Schema::create('form_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('form_submission_id')->constrained()->cascadeOnDelete();

            // Kept for grouping and for reading the field's type back; the
            // words below are what makes the answer readable without it.
            $table->foreignId('form_field_id')->nullable()->constrained()->nullOnDelete();

            $table->string('field_key', 60);
            $table->string('question');

            // Copied for the same reason the question is: the type is what
            // turns a stored value back into a sentence, and an answer whose
            // field was deleted still has to read as "ja" rather than as "1".
            $table->string('type', 20);

            /*
             * json rather than text, because a multiple-choice answer is a list
             * and a yes/no is a boolean. Everything that reads this goes
             * through FormFieldType::display(), which is where a value becomes
             * a sentence.
             */
            $table->json('value')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_answers');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
    }
};
