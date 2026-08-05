<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Take the punctuation back out of the keys a form's questions are known by.
     *
     * The first version of Form::keyFor() ran the label through snake(), which
     * leaves everything that is not a letter alone. A question is written as a
     * question, so "Wat gaat er fout?" became the key "wat_gaat_er_fout?" — and
     * ResolveVariables reads variables with a pattern that accepts no
     * punctuation at all. The result was the worst of the three possible
     * outcomes: the key existed, the builder offered it, and
     * {{ trigger.answers.wat_gaat_er_fout? }} sat in the ticket title as
     * literal text, because the pattern never matched it in the first place.
     *
     * keyFor() strips it now. This is the same rule applied to the rows that
     * were written before it did.
     *
     * The answers are rewritten in the same breath. They carry their own copy
     * of the key — see the forms migration for why — and a submission left
     * pointing at the old spelling would be a submission a workflow could not
     * read, which is the whole problem this fixes.
     *
     * What this cannot repair is the workflow text itself. A step that says
     * {{ trigger.answers.wat_gaat_er_fout? }} still says it afterwards, and it
     * was never going to work: rewriting somebody's sentence to guess at what
     * they meant is a worse habit than leaving one line to be retyped. The
     * builder shows the current key beside every question.
     */
    public function up(): void
    {
        $forms = DB::table('form_fields')->distinct()->pluck('form_id');

        foreach ($forms as $formId) {
            $this->tidy((string) $formId);
        }
    }

    /**
     * Not undone.
     *
     * Putting the question marks back would be restoring rows to a state where
     * a workflow could not read them, and the keys were only ever an internal
     * name — nothing outside this application quotes them.
     */
    public function down(): void {}

    private function tidy(string $formId): void
    {
        $fields = DB::table('form_fields')
            ->where('form_id', $formId)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'key']);

        /*
         * Cleaned once up front, because a suffix has to be judged against the
         * whole set. "wat_verwacht_je?_2" only carries that _2 because the
         * punctuated spelling of the same question was counted as a second
         * field; with the punctuation gone the plain word is free, and keeping
         * the number would leave every form explaining a collision that no
         * longer exists.
         */
        $cleaned = [];

        foreach ($fields as $field) {
            $cleaned[$field->id] = $this->clean((string) $field->key);
        }

        $taken = [];

        foreach ($fields as $field) {
            $wanted = $this->withoutStraySuffix($cleaned[$field->id], $cleaned, $field->id);

            // Uniqueness has to hold inside the form: two questions whose keys
            // differed only in punctuation collapse onto the same word, and the
            // second of them takes a suffix the way a new field would.
            $key = $wanted;
            $suffix = 2;

            while (in_array($key, $taken, true)) {
                $key = $wanted.'_'.$suffix++;
            }

            $taken[] = $key;

            if ($key === $field->key) {
                continue;
            }

            DB::table('form_fields')->where('id', $field->id)->update(['key' => $key]);

            /*
             * Every answer that named the old key, including those whose
             * question has since been deleted — matched through the submission
             * rather than through form_field_id for exactly that reason.
             */
            DB::table('form_answers')
                ->whereIn(
                    'form_submission_id',
                    DB::table('form_submissions')->where('form_id', $formId)->select('id')
                )
                ->where('field_key', $field->key)
                ->update(['field_key' => $key]);
        }
    }

    /**
     * The key without a trailing number, where nothing else wants that word.
     *
     * Only ever drops a suffix this application put there itself: two questions
     * that genuinely share a label still collide, the base is still taken, and
     * the second of them keeps its number.
     *
     * @param  array<int, string>  $cleaned
     */
    private function withoutStraySuffix(string $key, array $cleaned, int $id): string
    {
        $base = (string) preg_replace('/_\d+$/', '', $key);

        if ($base === $key || $base === '') {
            return $key;
        }

        foreach ($cleaned as $other => $candidate) {
            if ($other !== $id && $candidate === $base) {
                return $key;
            }
        }

        return $base;
    }

    /** The same rule Form::keyFor() applies, kept in step with it by hand. */
    private function clean(string $key): string
    {
        $clean = Str::of($key)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(50, '')
            ->trim('_')
            ->toString();

        return $clean === '' ? 'veld' : $clean;
    }
};
