<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Which of the bundled typefaces a given screen has to be handed.
 *
 * The @fonts directive is not only a preload hint: a family left out of its
 * argument has no @font-face rule on the page either, so it silently falls
 * through to whatever the browser calls sans-serif. That makes this list the
 * difference between the house style being worn and being merely named — which
 * is how the huisstijl went missing once already, when two faces were added to
 * vite.config.ts and nothing was taught to ask for them.
 *
 * Naming the screens rather than loading everything is the point: all eight
 * bundled families come to twenty-three preloaded weights, and the chat screen
 * that people actually sit in needs one of them.
 */
final class PageFonts
{
    /**
     * The two faces of the house style, worn by every screen in the .postduif
     * shell — see the `--pd-sans` and `--pd-mono` tokens in app.css.
     */
    private const HUISSTIJL = ['ibm-plex-sans', 'ibm-plex-mono'];

    /**
     * The script face, and it is here for a single box: the typed alternative
     * to drawing a signature. The browser paints it into a canvas and uploads
     * the pixels, so a face that never arrived does not look wrong — it is what
     * gets stored on the contract. See signature-pad.tsx.
     */
    private const SCRIPT = 'caveat';

    /**
     * Inertia components that render inside the .postduif shell.
     *
     * Everything outside the signed-in application, in other words: the public
     * site, the one-card auth screens the download, secrets and forms pages
     * borrow, the installer, and the signing page. The mapping from component
     * to shell lives in app.tsx; this follows it.
     *
     * @var list<string>
     */
    private const HUISSTIJL_PAGES = [
        'auth/',
        'contracts/',
        'forms/',
        'install/',
        'marketing/',
        'secrets/',
        'transfers/',
        'workspaces/',
    ];

    /**
     * @param  string  $component  The Inertia page component, e.g. 'marketing/home'.
     * @param  string|null  $workspaceFont  The alias from WorkspaceFont, or null for the system stack.
     * @return list<string>
     */
    public static function for(string $component, ?string $workspaceFont): array
    {
        $aliases = $workspaceFont === null ? [] : [$workspaceFont];

        if (Str::startsWith($component, self::HUISSTIJL_PAGES)) {
            $aliases = [...$aliases, ...self::HUISSTIJL];
        }

        if (Str::startsWith($component, 'contracts/')) {
            $aliases[] = self::SCRIPT;
        }

        return array_values(array_unique($aliases));
    }
}
