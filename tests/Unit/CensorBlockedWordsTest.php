<?php

use App\Actions\Chat\CensorBlockedWords;

/**
 * @param  array<int, string>  $words
 */
function censor(string $text, array $words): string
{
    return (new CensorBlockedWords)->handle($text, $words);
}

it('masks a blocked word', function () {
    expect(censor('wat een sukkel', ['sukkel']))->toBe('wat een ******');
});

it('ignores casing', function () {
    expect(censor('SUKKEL, Sukkel, sukkel', ['sukkel']))
        ->toBe('******, ******, ******');
});

it('leaves longer words that merely contain a blocked one alone', function () {
    expect(censor('de klas is klassiek en verklast', ['klas']))
        ->toBe('de **** is klassiek en verklast');
});

it('does not fire inside a word that continues with a digit', function () {
    expect(censor('klas klas2', ['klas']))->toBe('**** klas2');
});

it('masks every blocked word in one message', function () {
    expect(censor('sukkel en sul', ['sukkel', 'sul']))->toBe('****** en ***');
});

it('handles accents on both sides of the match', function () {
    expect(censor('een café, twee cafés', ['café']))->toBe('een ****, twee cafés');
});

it('counts an accented character as one star', function () {
    expect(censor('idioot é', ['é']))->toBe('idioot *');
});

it('masks a whole phrase rather than half of it', function () {
    expect(censor('dit is oude kaas', ['kaas', 'oude kaas']))
        ->toBe('dit is *********');
});

it('treats regex characters in the blocklist as literal text', function () {
    expect(censor('a.c en abc', ['a.c']))->toBe('*** en abc');
});

it('returns the text untouched when nothing is blocked', function () {
    expect(censor('gewoon een bericht', []))->toBe('gewoon een bericht');
});

it('shrugs off empty entries in the blocklist', function () {
    expect(censor('gewoon een bericht', ['', '   ']))->toBe('gewoon een bericht');
});

it('trims entries so a stray space still blocks the word', function () {
    expect(censor('wat een sukkel', [' sukkel ']))->toBe('wat een ******');
});

it('reuses its compiled pattern across calls', function () {
    $censor = new CensorBlockedWords;

    expect($censor->handle('sukkel', ['sukkel']))->toBe('******')
        ->and($censor->handle('ook sukkel', ['sukkel']))->toBe('ook ******');
});
