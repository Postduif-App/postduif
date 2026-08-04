import type { HighlighterCore } from 'shiki/core';

/**
 * One token of highlighted code: the characters, and the colours for both
 * schemes.
 *
 * Deliberately not HTML. Shiki will happily hand back a finished `<pre>` string,
 * and taking it would mean dangerouslySetInnerHTML on text a user typed — the
 * exact injection surface inline-markdown.ts refuses to open. Shiki's escaping is
 * sound, but "this particular string is safe" is a thing somebody has to keep
 * being right about, and tokens are a shape where the question never comes up.
 */
export interface CodeToken {
    content: string;
    /** Inline custom properties: --shiki-light and --shiki-dark. */
    style: Record<string, string>;
}

/**
 * The languages worth carrying, and the names people actually type for them.
 *
 * A list rather than everything Shiki knows: the full bundle is megabytes of
 * grammars, and a chat is not an editor. Anything not named here still renders
 * as a code block, just without colour — which is the right failure, because the
 * alternative is a message that shows nothing while a grammar downloads.
 */
const LANGUAGES = {
    bash: () => import('@shikijs/langs/bash'),
    blade: () => import('@shikijs/langs/blade'),
    css: () => import('@shikijs/langs/css'),
    html: () => import('@shikijs/langs/html'),
    javascript: () => import('@shikijs/langs/javascript'),
    json: () => import('@shikijs/langs/json'),
    php: () => import('@shikijs/langs/php'),
    python: () => import('@shikijs/langs/python'),
    sql: () => import('@shikijs/langs/sql'),
    typescript: () => import('@shikijs/langs/typescript'),
    vue: () => import('@shikijs/langs/vue'),
    yaml: () => import('@shikijs/langs/yaml'),
} as const;

type Language = keyof typeof LANGUAGES;

/**
 * What somebody types, and the grammar it means.
 *
 * "js" and "ts" are here because nobody writes ```javascript in a chat, and a
 * block that silently loses its colour over an abbreviation reads as broken
 * rather than as unsupported.
 */
const ALIASES: Record<string, Language> = {
    sh: 'bash',
    shell: 'bash',
    zsh: 'bash',
    js: 'javascript',
    jsx: 'javascript',
    ts: 'typescript',
    tsx: 'typescript',
    py: 'python',
    yml: 'yaml',
    'blade.php': 'blade',
};

/** The grammar a fence's label asks for, or null when we do not carry it. */
export function resolveLanguage(label: string | null): Language | null {
    if (label === null) {
        return null;
    }

    const name = label.toLowerCase();

    if (name in ALIASES) {
        return ALIASES[name];
    }

    return name in LANGUAGES ? (name as Language) : null;
}

/**
 * The highlighter, built once and shared.
 *
 * A promise rather than the instance, so ten code blocks arriving in one
 * message wait on one construction instead of racing to start ten. Everything
 * about it is loaded on demand: the module is only reached from a message that
 * actually contains a fence, so a workspace that never posts code never pays for
 * any of this.
 */
let highlighter: Promise<HighlighterCore> | null = null;

function core(): Promise<HighlighterCore> {
    return (highlighter ??= (async () => {
        const [
            { createHighlighterCore },
            { createJavaScriptRegexEngine },
            light,
            dark,
        ] = await Promise.all([
            import('shiki/core'),
            /*
             * The JavaScript engine rather than Oniguruma. Oniguruma is more
             * faithful on exotic grammars and costs a WebAssembly download to
             * say so; for the dozen languages above the difference does not
             * show, and a chat should not fetch a wasm binary to colour four
             * lines of PHP.
             */
            import('shiki/engine/javascript'),
            import('@shikijs/themes/github-light'),
            import('@shikijs/themes/github-dark'),
        ]);

        return createHighlighterCore({
            themes: [light.default, dark.default],
            langs: [],
            engine: createJavaScriptRegexEngine(),
        });
    })());
}

/** Grammars already pulled in, so a second block in the same language is free. */
const loaded = new Set<Language>();

/**
 * Colour a block of code.
 *
 * Both themes at once with `defaultColor: false`, which makes every token carry
 * `--shiki-light` and `--shiki-dark` instead of a colour. The stylesheet picks
 * between them, so a code block follows the theme switch without this having to
 * know the theme, re-run, or re-render — see the `.pd-code` rules in app.css.
 *
 * Returns null when the language is one we do not carry, which the caller draws
 * as a plain monospace block.
 */
export async function highlight(
    code: string,
    label: string | null,
): Promise<CodeToken[][] | null> {
    const lang = resolveLanguage(label);

    if (lang === null) {
        return null;
    }

    const shiki = await core();

    if (!loaded.has(lang)) {
        await shiki.loadLanguage(
            await LANGUAGES[lang]().then((m) => m.default),
        );
        loaded.add(lang);
    }

    const { tokens } = shiki.codeToTokens(code, {
        lang,
        themes: { light: 'github-light', dark: 'github-dark' },
        defaultColor: false,
    });

    return tokens.map((line) =>
        line.map((token) => ({
            content: token.content,
            // htmlStyle is the object form when defaultColor is false; the cast
            // is safe for exactly that reason and nowhere else.
            style: (token.htmlStyle ?? {}) as Record<string, string>,
        })),
    );
}
