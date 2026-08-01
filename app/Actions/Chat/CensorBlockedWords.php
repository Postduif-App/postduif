<?php

namespace App\Actions\Chat;

class CensorBlockedWords
{
    /**
     * Compiled patterns, keyed by the blocklist they were built from.
     *
     * A channel renders dozens of messages against the same list, and building
     * the pattern is the expensive half of the work.
     *
     * @var array<string, string>
     */
    private array $patterns = [];

    /**
     * Mask every blocked word in a piece of text.
     *
     * The replacement keeps the length of what it hides, so a censored word
     * still reads as a word inside the sentence — the point is to show that
     * someone used a word they should not have, not to make the text
     * unreadable.
     *
     * @param  array<int, string>  $words
     */
    public function handle(string $text, array $words): string
    {
        if ($words === [] || $text === '') {
            return $text;
        }

        $pattern = $this->pattern($words);

        if ($pattern === null) {
            return $text;
        }

        return preg_replace_callback(
            $pattern,
            fn (array $match): string => str_repeat('*', mb_strlen($match[0])),
            $text,
        ) ?? $text;
    }

    /**
     * Take every blocked word out of a piece of text altogether.
     *
     * Masking is for text somebody reads; this is for text the application
     * acts on — a search term, where asterisks would simply become a word
     * nobody ever typed. What is left may be an empty string, and the caller
     * has to treat that as "nothing was asked".
     *
     * @param  array<int, string>  $words
     */
    public function strip(string $text, array $words): string
    {
        if ($words === [] || $text === '') {
            return $text;
        }

        $pattern = $this->pattern($words);

        if ($pattern === null) {
            return $text;
        }

        // Collapse the whitespace the removal leaves behind, so "een sukkel
        // van een deployment" does not arrive as a term with a hole in it.
        return trim((string) preg_replace([$pattern, '/\s+/u'], ['', ' '], $text));
    }

    /**
     * One alternation over the whole blocklist, so a message is scanned once
     * however long the list grows.
     *
     * Longest first: with both "kaas" and "oude kaas" on the list, alternation
     * takes the first branch that matches, and the shorter one would otherwise
     * leave half the phrase standing.
     *
     * @param  array<int, string>  $words
     */
    private function pattern(array $words): ?string
    {
        $key = implode("\0", $words);

        if (array_key_exists($key, $this->patterns)) {
            return $this->patterns[$key];
        }

        $words = array_values(array_filter(
            array_map(trim(...), $words),
            fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            return null;
        }

        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $alternation = implode('|', array_map(
            fn (string $word): string => preg_quote($word, '/'),
            $words,
        ));

        // The boundaries are spelled out rather than using \b, which sits
        // between a word character and a non-word one and counts an accented
        // letter as the latter — "café" would then match inside "cafés" but
        // not on its own. Letters, digits and underscores all count as "still
        // part of a word" here, so a blocked word never fires inside a longer
        // one.
        return $this->patterns[$key] = '/(?<![\p{L}\p{N}_])(?:'.$alternation.')(?![\p{L}\p{N}_])/iu';
    }
}
