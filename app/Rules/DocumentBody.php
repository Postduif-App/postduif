<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A document document, checked for shape rather than for meaning.
 *
 * The shape of what is inside a block belongs to whichever Yoopta plugins
 * happen to be installed, and it moves when that list does. Validating it
 * properly would mean keeping a second copy of the editor's schema in PHP,
 * always one release behind — so this deliberately does not try.
 *
 * What it does check is the part that has nothing to do with the editor: that
 * this is a map of blocks, that it is not enormous, and that it is not nested
 * so deeply that reading it back is itself the attack. That is the difference
 * between "we trust the client" and "we accept anything at all". Whatever gets
 * through and is not a document the editor understands is a broken document for
 * the person who saved it, which is exactly as far as the damage should reach.
 */
class DocumentBody implements ValidationRule
{
    /**
     * Roughly a novel's worth of prose in JSON.
     *
     * Generous on purpose. This is the ceiling that stops a document being used
     * to fill the database, not a judgement about how long a document should
     * be — a runbook that has been added to for two years is a real thing, and
     * being told it is now too long to save would be the worst possible moment
     * to find that out.
     */
    private const MAX_BYTES = 2_000_000;

    /**
     * Deeper than any document the editor produces, shallower than anything
     * that makes json_decode work hard.
     *
     * Nested lists get to about six; the rest is headroom.
     */
    private const MAX_DEPTH = 30;

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('requests.documents.body_shape'));

            return;
        }

        /*
         * An empty document is legitimate — that is a document somebody just
         * started — and it arrives as an empty array from json_decode whether
         * the browser sent [] or {}. Nothing below it to check.
         */
        if ($value === []) {
            return;
        }

        /*
         * Yoopta keys its document by block id, so every key is a string. A
         * list arriving here means the browser sent an array where a map
         * belongs, which the editor would not be able to read back.
         */
        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                $fail(__('requests.documents.body_shape'));

                return;
            }
        }

        $encoded = json_encode($value);

        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            $fail(__('requests.documents.body_too_large'));

            return;
        }

        if ($this->depth($value) > self::MAX_DEPTH) {
            $fail(__('requests.documents.body_too_deep'));
        }
    }

    /**
     * How far down the nesting goes.
     *
     * Walked rather than measured with json_decode's depth argument, because by
     * the time this runs the decoding has already happened: the framework turns
     * the request body into an array before any rule sees it. So the cheap
     * guard is not available and this is the honest one.
     *
     * Iterative rather than recursive for the same reason the limit exists at
     * all — a deeply nested document should be refused, not answered with a
     * stack overflow.
     *
     * @param  array<mixed>  $value
     */
    private function depth(array $value): int
    {
        $deepest = 1;
        $stack = [[$value, 1]];

        while ($stack !== []) {
            [$current, $level] = array_pop($stack);

            if ($level > $deepest) {
                $deepest = $level;
            }

            // No point walking further once it is already refused.
            if ($deepest > self::MAX_DEPTH) {
                return $deepest;
            }

            foreach ($current as $child) {
                if (is_array($child)) {
                    $stack[] = [$child, $level + 1];
                }
            }
        }

        return $deepest;
    }
}
