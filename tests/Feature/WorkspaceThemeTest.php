<?php

/*
 * In Feature rather than Unit since the enum labels became translations: a
 * label now goes through the translator, and the translator needs a booted
 * application. The rest of what these check is still pure — the move costs a
 * little speed and buys the assertion being able to run at all.
 */

use App\Enums\WorkspaceAccent;
use App\Enums\WorkspaceFont;
use App\Models\Workspace;

it('gives every workspace a theme before it is ever saved', function () {
    $workspace = new Workspace;

    expect($workspace->accent)->toBe(WorkspaceAccent::Neutral)
        ->and($workspace->font)->toBe(WorkspaceFont::InstrumentSans);
});

it('carries a light and a dark variant for every accent', function (WorkspaceAccent $accent) {
    $variables = [
        'light' => $accent->variables('light'),
        'dark' => $accent->variables('dark'),
    ];

    expect($variables['light'])->toHaveKeys(['--primary', '--primary-foreground', '--ring'])
        ->and($variables['light']['--primary'])->toStartWith('oklch(')
        ->and($variables['dark']['--primary'])->toStartWith('oklch(');
})->with(WorkspaceAccent::cases());

it('never leaves the accent and its text colour the same', function (WorkspaceAccent $accent) {
    foreach (['light', 'dark'] as $scheme) {
        $swatch = $accent->swatch()[$scheme];

        expect($swatch['color'])->not->toBe($swatch['foreground']);
    }
})->with(WorkspaceAccent::cases());

it('ends every font stack in a fallback the browser already has', function (WorkspaceFont $font) {
    expect($font->stack())->toEndWith("'Noto Color Emoji'")
        ->and($font->label())->not->toBeEmpty();
})->with(WorkspaceFont::cases());

/*
 * The one thing BuildThemeStyles cannot check about itself.
 *
 * It writes --font-sans to :root and that is all it can do; whether anything
 * reads that variable is decided in app.css, by whether the token sits in
 * `@theme` or `@theme inline`. Inlined, Tailwind spells the default face out
 * inside `.font-sans` and the workspace's choice lands nowhere — a regression
 * with no error, no failing assertion anywhere else, and nothing to see but the
 * wrong letters. So it is checked against the compiled stylesheet, which is the
 * only place the answer exists.
 */
it('routes the font utility through the variable the theme overrides', function () {
    $build = dirname(__DIR__, 2).'/public/build';

    $manifest = json_decode((string) file_get_contents($build.'/manifest.json'), true);
    $css = file_get_contents($build.'/'.$manifest['resources/css/app.css']['file']);

    expect($css)->toContain('var(--font-sans)');
})->skip(
    ! file_exists(dirname(__DIR__, 2).'/public/build/manifest.json'),
    'Needs built assets; run npm run build.'
);
