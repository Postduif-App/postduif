<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The typeface a workspace reads in.
 *
 * Every case except System is bundled through the Vite fonts plugin, so
 * choosing one costs a stylesheet that is already on the page rather than a
 * request to somebody else's server. System is kept as the first alternative
 * for the same reason: it costs nothing at all.
 */
enum WorkspaceFont: string implements HasLabel
{
    case InstrumentSans = 'instrument-sans';
    case Inter = 'inter';
    case Figtree = 'figtree';
    case Ubuntu = 'ubuntu';
    case JetBrainsMono = 'jetbrains-mono';
    case System = 'system';

    /**
     * The fallbacks every stack ends in — emoji included, because a message
     * without them renders as boxes.
     */
    private const FALLBACKS = "ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'";

    /**
     * A monospaced face falls back to another monospaced one — landing in a
     * proportional stack would be a different typeface altogether, not a near
     * miss.
     */
    private const MONO_FALLBACKS = "ui-monospace, 'SFMono-Regular', 'Menlo', monospace, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'";

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::InstrumentSans => __('enums.workspace-font.label.InstrumentSans'),
            self::Inter => __('enums.workspace-font.label.Inter'),
            self::Figtree => __('enums.workspace-font.label.Figtree'),
            self::Ubuntu => __('enums.workspace-font.label.Ubuntu'),
            self::JetBrainsMono => __('enums.workspace-font.label.JetBrainsMono'),
            self::System => __('enums.workspace-font.label.System'),
        };
    }

    /**
     * The alias this family is bundled under in vite.config.ts, or null when it
     * is not bundled at all because the reader already has it installed.
     *
     * Fed to the @fonts directive so a page downloads the one face it is about
     * to render instead of all of them.
     */
    public function alias(): ?string
    {
        return $this === self::System ? null : $this->value;
    }

    /**
     * The value for --font-sans, fallbacks and all.
     */
    public function stack(): string
    {
        return match ($this) {
            self::InstrumentSans => "'Instrument Sans', ".self::FALLBACKS,
            self::Inter => "'Inter', ".self::FALLBACKS,
            self::Figtree => "'Figtree', ".self::FALLBACKS,
            self::Ubuntu => "'Ubuntu', ".self::FALLBACKS,
            self::JetBrainsMono => "'JetBrains Mono', ".self::MONO_FALLBACKS,
            self::System => self::FALLBACKS,
        };
    }
}
