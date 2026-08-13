<?php

/**
 * The public site answers in the reader's language.
 *
 * The mechanism was already there — HandleLocale reads Accept-Language, which
 * is the only preference somebody without an account has ever expressed. What
 * was missing is that the copy was written into the components in Dutch, so
 * the site answered Dutch whatever the reader asked for.
 *
 * Asserted against the rendered HTML rather than the props: these strings are
 * drawn by React, so anything short of a render would pass while the page in
 * front of somebody stayed Dutch.
 */
beforeEach(function () {
    skipWithoutSsr();
});

it('greets a Dutch reader in Dutch', function () {
    $html = $this->withHeader('Accept-Language', 'nl')
        ->get(route('home'))
        ->getContent();

    expect($html)->toContain('Inloggen')
        ->and($html)->toContain('Een werkplek voor gesprekken');
});

it('greets an English reader in English', function () {
    $html = $this->withHeader('Accept-Language', 'en')
        ->get(route('home'))
        ->getContent();

    expect($html)->toContain('Log in')
        ->and($html)->toContain('A workplace for conversations')
        // And not the other one. A half-translated shell is worse than a
        // Dutch one, because it reads as broken rather than as foreign.
        ->and($html)->not->toContain('Een werkplek voor gesprekken');
});

it('falls back to Dutch for a language it does not have', function () {
    $html = $this->withHeader('Accept-Language', 'fr')
        ->get(route('home'))
        ->getContent();

    // getPreferredLanguage returns the first supported option when the header
    // asks for something else, and nl is first — see HandleLocale::SUPPORTED.
    expect($html)->toContain('Inloggen');
});

it('translates the shell on the API reference too', function () {
    $html = $this->withHeader('Accept-Language', 'en')
        ->get(route('docs'))
        ->getContent();

    // The shell is one component around every public page, so the reference
    // gets it without knowing anything about it.
    expect($html)->toContain('Log in')
        ->and($html)->toContain('A workplace for conversations');
});

it('translates the homepage itself, not just the shell around it', function () {
    $html = $this->withHeader('Accept-Language', 'en')
        ->get(route('home'))
        ->getContent();

    expect($html)->toContain('The conversation and the work in one place')
        // A section head, a heading read off an enum, and the roles table:
        // three different kinds of copy, so an extraction that missed one shows
        // up here. The middle one is the interesting case — it is translated
        // where the enum lives rather than in the marketing file.
        ->toContain('What is in it')
        ->toContain('People from outside')
        ->toContain('Who may do what')
        ->and($html)->not->toContain('Wat er in zit')
        ->and($html)->not->toContain('Mensen van buiten');
});

it('counts the features in Dutch', function () {
    $html = $this->withHeader('Accept-Language', 'nl')->get(route('home'))->getContent();

    // The number comes from the inventory; only the noun beside it is copy.
    expect($html)->toContain('onderdelen');
});

it('counts the features in English', function () {
    $html = $this->withHeader('Accept-Language', 'en')->get(route('home'))->getContent();

    expect($html)->toContain('features');
});

it('translates the API reference into English', function () {
    $html = $this->withHeader('Accept-Language', 'en')->get(route('docs'))->getContent();

    expect($html)->toContain('The API')
        ->toContain('Signing in')
        ->toContain('With your own token')
        // A card label inside the endpoint list, which is a different
        // component from the section heads around it.
        ->toContain('What it returns')
        ->and($html)->not->toContain('Aanmelden')
        ->and($html)->not->toContain('Wat het teruggeeft');
});

it('keeps the API reference in Dutch for a Dutch reader', function () {
    $html = $this->withHeader('Accept-Language', 'nl')->get(route('docs'))->getContent();

    expect($html)->toContain('De API')
        ->toContain('Aanmelden')
        ->toContain('Wat het teruggeeft');
});

it('says the rate limit in the reader language', function () {
    $html = $this->withHeader('Accept-Language', 'en')->get(route('docs'))->getContent();

    // The number is read off the limiter; only the words around it are copy.
    expect($html)->toContain('At most 60 per minute');
});
