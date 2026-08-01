<?php

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
