<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Mail\RenderMailTemplate;
use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\MailPlaceholder;
use App\Enums\MailTemplateKind;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleLocale;
use App\Models\Workspace;
use App\Models\WorkspaceMailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this workspace's contract mails say.
 *
 * A screen of its own next to the mail settings rather than a block on them,
 * and the split is between two different afternoons: that one is where mail
 * leaves from — a transport, an API key, a test message — and this one is what
 * it says. Somebody setting up Postmark is not also rewriting their tone of
 * voice, and the reverse is even more true.
 *
 * Every field on it is optional in the strongest sense: leaving one empty is not
 * "no text" but "use the platform's", and the screen shows ours greyed out in
 * the box so that the difference is visible before anybody types. That is why
 * update() writes null rather than '' and deletes a row somebody emptied — see
 * WorkspaceMailTemplate::isEmpty.
 */
class WorkspaceMailTemplateController extends Controller
{
    use ResolvesCurrentWorkspace;

    /** What a body may be, in characters. Long enough for a letter, short
     * enough that nobody pastes a contract into the mail about the contract. */
    private const BODY_LIMIT = 5000;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        return Inertia::render('settings/workspace-mail-templates', [
            'workspace' => ['name' => $workspace->name],
            'kinds' => $this->kinds(),
            'locales' => $this->locales(),
            /*
             * Ours and theirs, side by side.
             *
             * The defaults are sent along rather than looked up in the front
             * end, because they are translations and the screen would otherwise
             * need its own copy of every sentence in every language. They land
             * in the placeholder attribute of an empty field, which is the whole
             * of how this screen explains what "leeg laten" does.
             */
            'defaults' => $this->defaults(),
            'templates' => $this->saved($workspace),
            'limits' => ['body' => self::BODY_LIMIT],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $validated = $request->validate([
            'templates' => ['present', 'array'],
            'templates.*.kind' => ['required', Rule::enum(MailTemplateKind::class)],
            'templates.*.locale' => ['required', Rule::in(HandleLocale::SUPPORTED)],
            'templates.*.subject' => ['nullable', 'string', 'max:255'],
            'templates.*.heading' => ['nullable', 'string', 'max:255'],
            'templates.*.body' => ['nullable', 'string', 'max:'.self::BODY_LIMIT],
            'templates.*.button_label' => ['nullable', 'string', 'max:80'],
        ]);

        $this->refusePlaceholdersNobodyCanFill($validated['templates']);

        foreach ($validated['templates'] as $row) {
            $kind = MailTemplateKind::from($row['kind']);
            $fields = [
                /*
                 * Blank becomes null, deliberately. A form always submits its
                 * fields, so an untouched box arrives as '' — and '' stored as
                 * a heading is a workspace claiming its mail should start with
                 * nothing, which nobody means and which no fallback would ever
                 * undo.
                 */
                'subject' => $this->orNull($row['subject'] ?? null),
                'heading' => $this->orNull($row['heading'] ?? null),
                'body' => $this->orNull($row['body'] ?? null),
                'button_label' => $this->orNull($row['button_label'] ?? null),
            ];

            $template = $workspace->mailTemplates()
                ->firstOrNew(['kind' => $kind, 'locale' => $row['locale']]);

            $template->fill($fields);

            /*
             * A row that says nothing is deleted rather than saved. It would
             * behave identically either way — that is the invariant — but a
             * table full of empty rows is a table that makes every reader ask
             * whether they mean something.
             */
            if ($template->isEmpty()) {
                $template->exists ? $template->delete() : null;

                continue;
            }

            $workspace->mailTemplates()->save($template);
        }

        return back()->with('status', __('mail_templates.saved'));
    }

    /**
     * The mail as it would arrive, from text that has not been saved.
     *
     * Not from the stored row, and that is the point: somebody is trying
     * something out, and a preview of what they last saved would answer a
     * question they are not asking. It also means this endpoint renders text
     * straight from the request, which is exactly why it goes through
     * RenderMailTemplate like everything else — the defusing of anything that
     * looks like a tag happens there and not in a second, hopeful place.
     */
    public function preview(Request $request, RenderMailTemplate $render): JsonResponse
    {
        $this->currentWorkspace($request);

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(MailTemplateKind::class)],
            'locale' => ['required', Rule::in(HandleLocale::SUPPORTED)],
            'subject' => ['nullable', 'string', 'max:255'],
            'heading' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:'.self::BODY_LIMIT],
            'button_label' => ['nullable', 'string', 'max:80'],
        ]);

        $kind = MailTemplateKind::from($validated['kind']);
        $locale = $validated['locale'];

        $template = new WorkspaceMailTemplate([
            'subject' => $this->orNull($validated['subject'] ?? null),
            'heading' => $this->orNull($validated['heading'] ?? null),
            'body' => $this->orNull($validated['body'] ?? null),
            'button_label' => $this->orNull($validated['button_label'] ?? null),
        ]);

        $rendered = $render->handle($kind, $template, $this->sampleValues($locale), $locale);

        /*
         * Rendered by the mail renderer rather than by a second one built for
         * previews. A preview that goes down its own path is a preview that can
         * be right about a mail that is wrong.
         *
         * The language is switched around the render and put back afterwards.
         * Nothing in these two views translates any more — every sentence in
         * them came from RenderMailTemplate — but the mail layout around them
         * still does, and somebody previewing their English text should see the
         * English footer under it.
         */
        $was = App::getLocale();
        App::setLocale($locale);

        try {
            $html = (string) app(Markdown::class)->render(
                $kind === MailTemplateKind::ContractRequest ? 'mail.contract-request' : 'mail.contract-signed',
                [
                    'heading' => $rendered->heading,
                    'before' => $rendered->before,
                    'after' => $rendered->after,
                    'buttonLabel' => $rendered->buttonLabel,
                    // Goes nowhere: a preview's button is there to be looked at.
                    'url' => '#',
                ],
            );
        } finally {
            App::setLocale($was);
        }

        return response()->json(['subject' => $rendered->subject, 'html' => $html]);
    }

    /**
     * Refuse a placeholder this mail could never fill in.
     *
     * Caught here rather than left to render as nothing, because of the line
     * rule: {{vervaldatum}} in the mail that carries a signed document would not
     * come out as a visible mistake, it would silently take its whole sentence
     * with it. The author would see a paragraph they wrote go missing and have
     * no way to know why.
     *
     * A name nobody wrote code for at all is left alone — that one does show up
     * verbatim in the preview, which is a good enough teacher.
     *
     * @param  list<array<string, mixed>>  $templates
     */
    private function refusePlaceholdersNobodyCanFill(array $templates): void
    {
        foreach ($templates as $index => $row) {
            $kind = MailTemplateKind::from($row['kind']);

            foreach (['subject', 'heading', 'body', 'button_label'] as $field) {
                $text = (string) ($row[$field] ?? '');

                preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $text, $matches);

                foreach ($matches[1] as $name) {
                    $placeholder = MailPlaceholder::matching($name);

                    if ($placeholder === null || $kind->allows($placeholder)) {
                        continue;
                    }

                    throw ValidationException::withMessages([
                        "templates.{$index}.{$field}" => __('mail_templates.placeholder_not_here', [
                            'placeholder' => $name,
                        ]),
                    ]);
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function kinds(): array
    {
        return array_values(collect(MailTemplateKind::cases())
            ->map(fn (MailTemplateKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'placeholders' => collect($kind->placeholders())
                    ->map(fn (MailPlaceholder $placeholder): array => [
                        'value' => $placeholder->value,
                        // What it is called in the language of whoever is
                        // editing, which is also what the chip inserts.
                        'token' => '{{'.$placeholder->label().'}}',
                        'label' => $placeholder->label(),
                        'hint' => $placeholder->hint(),
                    ])->values()->all(),
            ])->all());
    }

    /**
     * The languages this application has, not the ones a workspace uses.
     *
     * Read off HandleLocale so that a third language becomes a third tab on
     * this screen the day it becomes a third language anywhere else.
     *
     * @return list<array{value: string, label: string}>
     */
    private function locales(): array
    {
        return array_values(collect(HandleLocale::SUPPORTED)
            ->map(fn (string $locale): array => [
                'value' => $locale,
                'label' => (string) __('mail_templates.language_name.'.$locale),
            ])->all());
    }

    /**
     * The platform's own text for every kind in every language.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private function defaults(): array
    {
        $defaults = [];

        foreach (MailTemplateKind::cases() as $kind) {
            foreach (HandleLocale::SUPPORTED as $locale) {
                $defaults[$kind->value][$locale] = [
                    'subject' => (string) __($kind->translationKey('subject'), [], $locale),
                    'heading' => (string) __($kind->translationKey('heading'), [], $locale),
                    'body' => (string) __($kind->translationKey('body'), [], $locale),
                    'button_label' => (string) __($kind->translationKey('button'), [], $locale),
                ];
            }
        }

        return $defaults;
    }

    /**
     * What this workspace saved, indexed the way the screen reads it.
     *
     * Every slot is present whether or not there is a row behind it, so the
     * front end never has to ask whether a combination exists — an empty field
     * and a missing row are the same thing everywhere else in this feature and
     * they may as well be the same thing here too.
     *
     * @return array<string, array<string, array<string, string|null>>>
     */
    private function saved(Workspace $workspace): array
    {
        $rows = $workspace->mailTemplates()->get();
        $saved = [];

        foreach (MailTemplateKind::cases() as $kind) {
            foreach (HandleLocale::SUPPORTED as $locale) {
                $row = $rows->first(
                    fn (WorkspaceMailTemplate $template): bool => $template->kind === $kind
                        && $template->locale === $locale
                );

                $saved[$kind->value][$locale] = [
                    'subject' => $row?->subject,
                    'heading' => $row?->heading,
                    'body' => $row?->body,
                    'button_label' => $row?->button_label,
                ];
            }
        }

        return $saved;
    }

    /**
     * Stand-ins for a preview.
     *
     * Made up and recognisably so. A preview filled with a real contract would
     * invite somebody to check whether the mail is right for that client rather
     * than whether the text is right — and it would put a real name on a screen
     * that exists to be shown to a colleague.
     *
     * @return array<string, string>
     */
    private function sampleValues(string $locale): array
    {
        return [
            'signer' => (string) __('mail_templates.sample.signer', [], $locale),
            'sender' => (string) __('mail_templates.sample.sender', [], $locale),
            'workspace' => (string) __('mail_templates.sample.workspace', [], $locale),
            'title' => (string) __('mail_templates.sample.title', [], $locale),
            'message' => (string) __('mail_templates.sample.message', [], $locale),
            'expires' => now()->addDays(14)->settings(['locale' => $locale])->translatedFormat(__('mail.format.date', [], $locale)),
            'signed_at' => now()->settings(['locale' => $locale])->translatedFormat(__('mail.format.date_time', [], $locale)),
        ];
    }

    private function orNull(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }
}
