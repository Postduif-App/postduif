<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The accent a workspace paints itself in.
 *
 * A fixed list rather than a colour picker: these values are written straight
 * into CSS custom properties, so anything free-form would be both an injection
 * surface and a way to end up with white text on yellow. Every case carries its
 * own light and dark variant — the interface flips the whole palette in dark
 * mode, and an accent that only looked right in one of them would be half
 * broken for whoever chose the other.
 */
enum WorkspaceAccent: string implements HasLabel
{
    case Neutral = 'neutral';
    case Indigo = 'indigo';
    case Blue = 'blue';
    case Emerald = 'emerald';
    case Amber = 'amber';
    case Rose = 'rose';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Neutral => 'Neutraal',
            self::Indigo => 'Indigo',
            self::Blue => 'Blauw',
            self::Emerald => 'Groen',
            self::Amber => 'Amber',
            self::Rose => 'Roze',
        };
    }

    /**
     * The accent itself and the text that sits on top of it, per colour scheme.
     *
     * @return array{light: array{color: string, foreground: string}, dark: array{color: string, foreground: string}}
     */
    public function swatch(): array
    {
        return match ($this) {
            self::Neutral => [
                'light' => ['color' => 'oklch(0.205 0 0)', 'foreground' => 'oklch(0.985 0 0)'],
                'dark' => ['color' => 'oklch(0.922 0 0)', 'foreground' => 'oklch(0.205 0 0)'],
            ],
            self::Indigo => [
                'light' => ['color' => 'oklch(0.51 0.19 273)', 'foreground' => 'oklch(0.985 0 0)'],
                'dark' => ['color' => 'oklch(0.62 0.17 273)', 'foreground' => 'oklch(0.985 0 0)'],
            ],
            self::Blue => [
                'light' => ['color' => 'oklch(0.54 0.16 254)', 'foreground' => 'oklch(0.985 0 0)'],
                'dark' => ['color' => 'oklch(0.63 0.15 254)', 'foreground' => 'oklch(0.985 0 0)'],
            ],
            self::Emerald => [
                'light' => ['color' => 'oklch(0.52 0.13 165)', 'foreground' => 'oklch(0.985 0 0)'],
                'dark' => ['color' => 'oklch(0.62 0.13 165)', 'foreground' => 'oklch(0.145 0 0)'],
            ],
            self::Amber => [
                'light' => ['color' => 'oklch(0.68 0.15 70)', 'foreground' => 'oklch(0.21 0.04 70)'],
                'dark' => ['color' => 'oklch(0.75 0.15 70)', 'foreground' => 'oklch(0.21 0.04 70)'],
            ],
            self::Rose => [
                'light' => ['color' => 'oklch(0.55 0.19 15)', 'foreground' => 'oklch(0.985 0 0)'],
                'dark' => ['color' => 'oklch(0.64 0.18 15)', 'foreground' => 'oklch(0.985 0 0)'],
            ],
        };
    }

    /**
     * The custom properties to override for one colour scheme.
     *
     * The sidebar keeps its own copies of these in app.css, so an accent that
     * only set --primary would colour the buttons and leave the channel list
     * behind.
     *
     * @param  'light'|'dark'  $scheme
     * @return array<string, string>
     */
    public function variables(string $scheme): array
    {
        ['color' => $color, 'foreground' => $foreground] = $this->swatch()[$scheme];

        return [
            '--primary' => $color,
            '--primary-foreground' => $foreground,
            '--ring' => $color,
            '--sidebar-primary' => $color,
            '--sidebar-primary-foreground' => $foreground,
            '--sidebar-ring' => $color,
        ];
    }
}
