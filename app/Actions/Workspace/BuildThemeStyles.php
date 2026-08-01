<?php

namespace App\Actions\Workspace;

use App\Models\Workspace;

class BuildThemeStyles
{
    /**
     * The workspace's theme as a stylesheet the browser can apply as-is.
     *
     * Custom properties rather than classes: app.css already resolves every
     * colour utility through --primary and every letter through --font-sans, so
     * redefining those two families of variables re-themes the whole interface
     * without a single component knowing a theme exists. The dark block is a
     * second rule on .dark for the same reason the enum carries two swatches —
     * the palette flips, and an accent has to flip with it.
     *
     * Values come from enums exclusively, so nothing user-typed is ever
     * concatenated into a stylesheet.
     */
    public function handle(Workspace $workspace): string
    {
        $font = "--font-sans: {$workspace->font->stack()};";

        return ':root{'.$this->declarations($workspace->accent->variables('light')).$font.'}'
            .'.dark{'.$this->declarations($workspace->accent->variables('dark')).'}';
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function declarations(array $variables): string
    {
        return collect($variables)
            ->map(fn (string $value, string $property): string => "{$property}: {$value};")
            ->implode('');
    }
}
