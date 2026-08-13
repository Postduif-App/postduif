<?php

namespace App\Actions\Mail;

use App\Enums\MailPlaceholder;
use App\Enums\MailTemplateKind;
use App\Models\WorkspaceMailTemplate;
use App\Support\Mail\RenderedMailTemplate;

/**
 * Turn what a workspace typed — or what the platform says when it typed
 * nothing — into the four pieces a mail view needs.
 *
 * One path for both, and that is the point. The tempting shape was "if there is
 * a template, render it; otherwise render the old view", which is two layouts
 * to keep in step and a guarantee that the custom one grows a bug the default
 * one does not have. Instead the platform's own text is itself a template, kept
 * in lang/{nl,en}/mail.php with the same placeholders in it, and a workspace
 * that overrides nothing simply gets ours through the same three steps.
 *
 * Those steps, in order:
 *
 *  1. Placeholders are filled in. A name nobody wrote code for is left standing
 *     exactly as typed — {{ondertekaar}} comes out as {{ondertekaar}} — because
 *     a typo you can see in the preview is worth more than a silent gap.
 *  2. A line holding a placeholder that came up empty is dropped whole. This is
 *     what makes one text serve a contract with a deadline and one without: the
 *     sentence about the deadline disappears with the date rather than reading
 *     "Deze link verloopt op ." A rule worth knowing about, and the settings
 *     screen says so out loud.
 *  3. The text is cut at {{knop}}. No {{knop}} means everything lands above it,
 *     so the button is appended rather than lost — see RenderedMailTemplate.
 *
 * Nothing here parses markdown. The output is markdown source and goes into the
 * ordinary mail view, so a workspace's paragraph is laid out by the same
 * renderer as ours. What this does do is defuse HTML in everything that came
 * from a person: raw tags become text, which is the difference between a
 * workspace admin styling a sentence and a workspace admin putting a
 * convincing fake button in somebody else's inbox.
 */
class RenderMailTemplate
{
    /**
     * @param  WorkspaceMailTemplate|null  $template  What the workspace wrote,
     *                                                or null for a workspace that wrote nothing — which is most of them,
     *                                                and which must render exactly what this application always sent.
     * @param  array<string, string|null>  $values  Keyed by placeholder value:
     *                                              ['signer' => 'Anna', 'expires' => '3 maart 2027']. A key that is
     *                                              missing or blank is what triggers the line rule above.
     * @param  string|null  $locale  Which language the platform's own text is
     *                               read in. Null for the current one, which is what a preview wants
     *                               and what a send never relies on — see SendContract.
     */
    public function handle(
        MailTemplateKind $kind,
        ?WorkspaceMailTemplate $template,
        array $values,
        ?string $locale = null,
    ): RenderedMailTemplate {
        [$before, $after] = $this->split(
            $this->fill($this->part($kind, $template, 'body', $locale, markdown: true), $values, markdown: true)
        );

        return new RenderedMailTemplate(
            /*
             * The subject is the one piece that never meets a markdown parser:
             * it is a line of plain text in somebody's inbox list. So it is the
             * one piece that is not defused — an escaped bracket here would be
             * read by nobody and seen by everybody.
             *
             * A button placeholder in it can only be a mistake, and is swept
             * rather than refused: somebody who pasted their body into the
             * subject field deserves a readable subject, not a literal {{knop}}
             * in every inbox.
             */
            subject: $this->sweep($this->fill($this->part($kind, $template, 'subject', $locale, markdown: false), $values, markdown: false)),
            heading: $this->sweep($this->fill($this->part($kind, $template, 'heading', $locale, markdown: true), $values, markdown: true)),
            before: $before,
            after: $after,
            buttonLabel: $this->sweep($this->fill($this->part($kind, $template, 'button', $locale, markdown: true), $values, markdown: true)),
        );
    }

    /**
     * What this workspace wants said here, or what the platform says.
     *
     * blank() rather than a null check, so that a field somebody emptied out in
     * the form behaves the same as one they never filled in. There is no way to
     * say "no heading at all" and there should not be: a mail with a blank where
     * its first line goes reads as broken rather than as minimal.
     */
    private function part(MailTemplateKind $kind, ?WorkspaceMailTemplate $template, string $part, ?string $locale, bool $markdown): string
    {
        $own = match ($part) {
            'subject' => $template?->subject,
            'heading' => $template?->heading,
            'body' => $template?->body,
            'button' => $template?->button_label,
            default => null,
        };

        if (filled($own)) {
            return $markdown ? $this->defuse($own) : $own;
        }

        return (string) __($kind->translationKey($part), [], $locale);
    }

    /**
     * Fill in the placeholders, line by line.
     *
     * Line by line rather than over the whole string because of the drop rule:
     * "this sentence has nothing to say" is a fact about a sentence, and a
     * sentence in a mail is a line. Blank lines the rule leaves behind are
     * collapsed at the end, so a dropped paragraph does not leave a hole where
     * the layout can see it.
     *
     * @param  array<string, string|null>  $values
     * @param  bool  $markdown  Whether what comes out is going to be parsed as
     *                          markdown, which decides whether the values a person typed have
     *                          their brackets defused. False for the subject, and nothing else.
     */
    private function fill(string $text, array $values, bool $markdown): string
    {
        $lines = preg_split("/\r\n|\r|\n/", $text) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $empty = false;

            $filled = preg_replace_callback(
                '/\{\{\s*([^{}]+?)\s*\}\}/u',
                function (array $match) use ($values, $line, $markdown, &$empty): string {
                    $placeholder = MailPlaceholder::matching($match[1]);

                    /*
                     * Two things are deliberately left standing. A name nobody
                     * knows, so the author can see their typo, and the button,
                     * which split() is still looking for — filling it in here
                     * would cut the text at a token that is no longer there.
                     */
                    if ($placeholder === null || $placeholder === MailPlaceholder::Button) {
                        return $match[0];
                    }

                    $value = $values[$placeholder->value] ?? null;

                    if (blank($value)) {
                        $empty = true;

                        return '';
                    }

                    return $markdown
                        ? $this->continued($this->defuse($value), $line)
                        : $value;
                },
                $line
            ) ?? $line;

            if ($empty) {
                continue;
            }

            $kept[] = $filled;
        }

        return $this->tidy(implode("\n", $kept));
    }

    /**
     * Keep a multi-line value inside the shape of the line it landed in.
     *
     * The case this exists for is the author's own note, which is a textarea
     * and arrives with newlines in it, dropped into a line that begins with
     * "> ". Without this, the first line of the note is quoted and the rest
     * falls out of the block and reads as the workspace talking.
     */
    private function continued(string $value, string $line): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if (! str_contains($value, "\n")) {
            return $value;
        }

        preg_match('/^(\s*(?:>\s?)+)/u', $line, $match);
        $prefix = $match[1] ?? '';

        return trim($prefix) === ''
            ? $value
            : str_replace("\n", "\n".$prefix, $value);
    }

    /**
     * Cut the body at the button.
     *
     * Whatever shares the line with {{knop}} keeps its side of it, so "Klaar?
     * {{knop}} Het duurt twee minuten." puts the question above the button and
     * the reassurance below. A second {{knop}} is swept away rather than
     * honoured: one mail, one button.
     *
     * @return array{string, string}
     */
    private function split(string $body): array
    {
        $pattern = $this->pattern(MailPlaceholder::Button);

        if (preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [$body, ''];
        }

        [$token, $offset] = $match[0];

        return [
            $this->tidy(substr($body, 0, $offset)),
            $this->tidy($this->sweep(substr($body, $offset + strlen($token)))),
        ];
    }

    /** Take out any button placeholder, wherever it ended up. */
    private function sweep(string $text): string
    {
        return $this->tidy((string) preg_replace($this->pattern(MailPlaceholder::Button), '', $text));
    }

    /**
     * What {{knop}} looks like in every language at once.
     *
     * Built from the aliases rather than from the current locale: a Dutch admin
     * types {{knop}} and the mail may still go out in English — see
     * MailPlaceholder.
     */
    private function pattern(MailPlaceholder $placeholder): string
    {
        $names = implode('|', array_map(
            fn (string $alias): string => preg_quote($alias, '/'),
            $placeholder->aliases()
        ));

        return '/\{\{\s*(?:'.$names.')\s*\}\}/iu';
    }

    /**
     * Close the gaps the drop rule leaves and trim the ends.
     *
     * Three newlines become two, which in markdown is the difference between a
     * paragraph break and a paragraph break with a ghost paragraph in it.
     */
    private function tidy(string $text): string
    {
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Make HTML in somebody's text read as text.
     *
     * Applied to everything a person wrote — the template, the contract title,
     * the author's note — and to nothing this application wrote itself. The
     * markdown that follows leaves &lt; alone, so a typed-out <b> arrives as
     * those three characters rather than as bold. Markdown formatting still
     * works, which is the whole trade: **vet** yes, a hand-built button that
     * goes somewhere else no.
     *
     * Only the opening bracket, and on purpose. A tag cannot start without one,
     * and escaping the closing bracket too would take blockquotes away from
     * everybody who writes "> " at the start of a line for the ordinary reason.
     *
     * Links are handled a layer down — Laravel's markdown converter is built
     * with allow_unsafe_links off, so a javascript: href never survives.
     */
    private function defuse(string $text): string
    {
        return str_replace('<', '&lt;', $text);
    }
}
