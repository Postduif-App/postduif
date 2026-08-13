<?php

/*
 * The @fonts directive filters the inlined @font-face rules, not only the
 * preload links — so a family the root template forgets to name is a family the
 * page cannot render in, however carefully it is bundled in vite.config.ts.
 *
 * That is not hypothetical: the house style went missing exactly that way, when
 * IBM Plex and Caveat were added to the bundle after the template had been
 * narrowed to the workspace's own face. These check the mapping and then check
 * the rendered page, because the mapping being right is only half of it.
 */

use App\Enums\WorkspaceFont;
use App\Support\PageFonts;

it('hands the house style to every screen in the postduif shell', function (string $component) {
    expect(PageFonts::for($component, 'instrument-sans'))
        ->toContain('ibm-plex-sans', 'ibm-plex-mono');
})->with([
    'auth/login',
    'contracts/sign',
    'forms/public',
    'install/welcome',
    'marketing/home',
    'marketing/docs',
    'secrets/reveal',
    'transfers/show',
    'workspaces/none',
]);

it('keeps the house style off the screens that never wear it', function (string $component) {
    expect(PageFonts::for($component, 'instrument-sans'))
        ->toBe(['instrument-sans']);
})->with([
    'chat/show',
    'settings/profile',
    'welcome',
]);

it('only ships the script face to the page that signs something', function () {
    expect(PageFonts::for('contracts/sign', null))->toContain('caveat')
        ->and(PageFonts::for('chat/contract-show', null))->not->toContain('caveat');
});

it('asks for nothing when the workspace reads in the system font', function () {
    expect(PageFonts::for('chat/show', WorkspaceFont::System->alias()))->toBe([]);
});

it('names each family once, however many reasons there are to load it', function () {
    expect(PageFonts::for('contracts/sign', 'ibm-plex-sans'))
        ->toBe(['ibm-plex-sans', 'ibm-plex-mono', 'caveat']);
});

/*
 * The root template rather than a route: whether the public site is reachable
 * depends on whether this installation has been set up, and what is under test
 * here is the <head>, not the front door.
 */
it('puts the house style in the head of a postduif page, face and all', function () {
    $html = view('app', [
        'page' => [
            'component' => 'marketing/home',
            'props' => ['theme' => ['font' => 'instrument-sans', 'css' => '']],
        ],
    ])->render();

    /*
     * The family names rather than the exact @font-face text: the dev server
     * and a built bundle write that block differently, and what matters is that
     * the declaration is there at all.
     */
    expect($html)->toContain('IBM Plex Mono')
        ->and($html)->toContain('IBM Plex Sans')
        ->and($html)->toContain('Instrument Sans')
        ->and($html)->not->toContain('Caveat');
})->skip(
    ! file_exists(dirname(__DIR__, 2).'/public/build/fonts-manifest.json')
        && ! file_exists(dirname(__DIR__, 2).'/public/hot'),
    'Needs a built font manifest or a running dev server.'
);
