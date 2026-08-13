<?php

namespace App\Support\Mail;

/**
 * One mail's text, filled in and cut in two around the button.
 *
 * A value object rather than an array because of what the two halves mean:
 * $before and $after are not "paragraph one and paragraph two" but "everything
 * the reader sees above the button" and "everything below it". A template that
 * never mentioned the button has an empty $after and loses nothing, which is
 * the behaviour that guarantees a signing request always has something to press.
 *
 * Everything here is markdown source, not HTML. It is handed to the same mail
 * view the platform's own text goes through, so a workspace's paragraph and
 * ours are laid out by one renderer and cannot drift apart.
 */
final readonly class RenderedMailTemplate
{
    public function __construct(
        public string $subject,
        public string $heading,
        public string $before,
        public string $after,
        public string $buttonLabel,
    ) {}
}
