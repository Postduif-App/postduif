import { Head, router, usePage } from '@inertiajs/react';
import { Eye, RotateCcw } from 'lucide-react';
import { useRef, useState } from 'react';

import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { mutatingHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import { preview, update } from '@/routes/workspace/mail-texts';

interface Placeholder {
    value: string;
    /** What the chip inserts, braces and all, in the reader's language. */
    token: string;
    label: string;
    hint: string;
}

interface Kind {
    value: string;
    label: string;
    description: string;
    placeholders: Placeholder[];
}

interface Fields {
    subject: string | null;
    heading: string | null;
    body: string | null;
    button_label: string | null;
}

type FieldName = 'subject' | 'heading' | 'body' | 'button_label';

type ByKindAndLocale = Record<string, Record<string, Fields>>;

/**
 * The platform's own text, which — unlike a workspace's — is never absent.
 * Separate from Fields for that one reason: it is what fills the placeholder
 * attribute, and a placeholder that could be null would need a fallback of its
 * own for a case that cannot happen.
 */
type Defaults = Record<string, Record<string, Record<FieldName, string>>>;

interface WorkspaceMailTemplateProps {
    workspace: { name: string };
    kinds: Kind[];
    locales: { value: string; label: string }[];
    /** The platform's own text, shown greyed out in an empty field. */
    defaults: Defaults;
    templates: ByKindAndLocale;
    limits: { body: number };
}

/**
 * What this workspace's contract mails say.
 *
 * Two choices decide the whole screen: which mail, and which language. Both are
 * pickers above a single set of fields rather than a page listing all four
 * combinations, because what somebody is doing here is writing one letter — and
 * a page showing four letters at once is a page where nobody knows which one
 * they are editing.
 *
 * Everything is held in one piece of state and submitted in one go, including
 * the combinations that were never opened. That is what lets the language tabs
 * be switched freely without losing what was typed on the other one, and it
 * costs nothing: an untouched combination submits exactly what it arrived as.
 */
export default function WorkspaceMailTemplates({
    workspace,
    kinds,
    locales,
    defaults,
    templates,
    limits,
}: WorkspaceMailTemplateProps) {
    const { t } = useTranslate();
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    const [kind, setKind] = useState(kinds[0].value);
    const [locale, setLocale] = useState(locales[0].value);
    const [values, setValues] = useState<ByKindAndLocale>(templates);
    const [saving, setSaving] = useState(false);
    const [previewing, setPreviewing] = useState(false);
    const [previewHtml, setPreviewHtml] = useState<string | null>(null);
    const [previewSubject, setPreviewSubject] = useState('');

    /*
     * The body box, kept so a chip can be inserted where the cursor is rather
     * than glued to the end. Somebody who wants a placeholder in the middle of
     * a sentence should not have to type the braces themselves.
     */
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    const current = values[kind][locale];
    const fallback = defaults[kind][locale];
    const chosenKind = kinds.find((option) => option.value === kind)!;

    const set = (field: FieldName, value: string) =>
        setValues((previous) => ({
            ...previous,
            [kind]: {
                ...previous[kind],
                [locale]: { ...previous[kind][locale], [field]: value },
            },
        }));

    /*
     * The index of this field in the flat list the server validates, so that an
     * error about templates.2.body lands under the box it is about. The order
     * has to be the one submit() builds — see there.
     */
    const errorFor = (field: FieldName): string | undefined => {
        const index =
            kinds.findIndex((option) => option.value === kind) *
                locales.length +
            locales.findIndex((option) => option.value === locale);

        return errors[`templates.${index}.${field}`];
    };

    const insert = (token: string) => {
        const box = bodyRef.current;
        const text = current.body ?? '';

        if (!box) {
            set('body', `${text}${token}`);

            return;
        }

        const start = box.selectionStart;
        const end = box.selectionEnd;

        set('body', `${text.slice(0, start)}${token}${text.slice(end)}`);

        // After React has written the new value, or the caret jumps to the end.
        requestAnimationFrame(() => {
            box.focus();
            box.setSelectionRange(start + token.length, start + token.length);
        });
    };

    /** Empty every field for this mail and this language: back to ours. */
    const reset = () =>
        setValues((previous) => ({
            ...previous,
            [kind]: {
                ...previous[kind],
                [locale]: {
                    subject: '',
                    heading: '',
                    body: '',
                    button_label: '',
                },
            },
        }));

    const submit = () => {
        setSaving(true);

        router.patch(
            update().url,
            {
                // Flattened in the order errorFor() assumes: kinds outermost.
                templates: kinds.flatMap((option) =>
                    locales.map((language) => ({
                        kind: option.value,
                        locale: language.value,
                        ...values[option.value][language.value],
                    })),
                ),
            },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    /*
     * Rendered by the server from what is in the form right now, not from what
     * was last saved — trying something out is the entire reason somebody
     * presses this.
     */
    const showPreview = async () => {
        setPreviewing(true);

        try {
            const response = await fetch(preview().url, {
                method: 'POST',
                headers: mutatingHeaders(),
                body: JSON.stringify({ kind, locale, ...current }),
            });

            if (!response.ok) {
                return;
            }

            const rendered = await response.json();

            setPreviewSubject(rendered.subject);
            setPreviewHtml(rendered.html);
        } finally {
            setPreviewing(false);
        }
    };

    return (
        <>
            <Head title={t('mail_templates.title')} />

            <SettingsSection
                title={t('mail_templates.title')}
                description={t('mail_templates.description', {
                    workspace: workspace.name,
                })}
            >
                <p className="max-w-prose text-sm text-muted-foreground">
                    {t('mail_templates.intro')}
                </p>

                <div className="space-y-8">
                    <fieldset className="grid gap-3">
                        <legend className="text-sm font-medium">
                            {t('mail_templates.kind')}
                        </legend>

                        <div className="grid gap-2">
                            {kinds.map((option) => (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'flex max-w-prose cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                        kind === option.value
                                            ? 'border-primary bg-primary/5'
                                            : 'hover:bg-muted/50',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={option.value}
                                        checked={kind === option.value}
                                        onChange={() => setKind(option.value)}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {option.label}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {option.description}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div className="grid gap-2">
                        <span className="text-sm font-medium">
                            {t('mail_templates.language')}
                        </span>

                        {/*
                            Tabs rather than a select. There are two of them
                            today and the point of the control is to show that
                            the other one exists — a language somebody forgot to
                            fill in is the failure mode this screen has.
                        */}
                        <div className="flex w-fit gap-1 rounded-lg bg-muted p-1">
                            {locales.map((language) => (
                                <button
                                    key={language.value}
                                    type="button"
                                    onClick={() => setLocale(language.value)}
                                    className={cn(
                                        'rounded-md px-3 py-1 text-sm transition-colors',
                                        locale === language.value
                                            ? 'bg-background shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {language.label}
                                </button>
                            ))}
                        </div>

                        <p className="max-w-prose text-xs text-muted-foreground">
                            {t('mail_templates.language_hint')}
                        </p>
                    </div>

                    <div className="grid max-w-prose gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="subject">
                                {t('mail_templates.subject')}
                            </Label>
                            <Input
                                id="subject"
                                value={current.subject ?? ''}
                                placeholder={fallback.subject}
                                onChange={(event) =>
                                    set('subject', event.target.value)
                                }
                            />
                            <InputError message={errorFor('subject')} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="heading">
                                {t('mail_templates.heading')}
                            </Label>
                            <Input
                                id="heading"
                                value={current.heading ?? ''}
                                placeholder={fallback.heading}
                                onChange={(event) =>
                                    set('heading', event.target.value)
                                }
                            />
                            <InputError message={errorFor('heading')} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="body">
                                {t('mail_templates.body')}
                            </Label>
                            <textarea
                                id="body"
                                ref={bodyRef}
                                rows={12}
                                maxLength={limits.body}
                                value={current.body ?? ''}
                                placeholder={fallback.body}
                                onChange={(event) =>
                                    set('body', event.target.value)
                                }
                                // Borrowed from Input, as the bio box is: two
                                // form kits on one screen read as a mistake.
                                className="block w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError message={errorFor('body')} />
                        </div>

                        <div className="grid gap-3 rounded-lg border bg-muted/30 p-3">
                            <span className="text-sm font-medium">
                                {t('mail_templates.placeholders')}
                            </span>

                            <div className="flex flex-wrap gap-1.5">
                                {chosenKind.placeholders.map((placeholder) => (
                                    <button
                                        key={placeholder.value}
                                        type="button"
                                        title={placeholder.hint}
                                        onClick={() =>
                                            insert(placeholder.token)
                                        }
                                        className={cn(
                                            'rounded-md border bg-background px-2 py-1 font-mono text-xs transition-colors hover:bg-muted',
                                            // The button is the one that must
                                            // not be forgotten, so it does not
                                            // look like the rest.
                                            placeholder.value === 'button' &&
                                                'border-primary/50 text-primary',
                                        )}
                                    >
                                        {placeholder.token}
                                    </button>
                                ))}
                            </div>

                            <p className="text-xs text-muted-foreground">
                                {t('mail_templates.placeholders_hint')}
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="button_label">
                                {t('mail_templates.button_label')}
                            </Label>
                            <Input
                                id="button_label"
                                value={current.button_label ?? ''}
                                placeholder={fallback.button_label}
                                onChange={(event) =>
                                    set('button_label', event.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('mail_templates.button_label_hint')}
                            </p>
                            <InputError message={errorFor('button_label')} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button onClick={submit} disabled={saving}>
                            {saving && <Spinner className="size-4" />}
                            {t('settings.actions.save')}
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            onClick={showPreview}
                            disabled={previewing}
                        >
                            {previewing ? (
                                <Spinner className="size-4" />
                            ) : (
                                <Eye className="size-4" />
                            )}
                            {t('mail_templates.preview')}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            onClick={reset}
                            title={t('mail_templates.reset_confirm')}
                        >
                            <RotateCcw className="size-4" />
                            {t('mail_templates.reset')}
                        </Button>
                    </div>
                </div>
            </SettingsSection>

            <Dialog
                open={previewHtml !== null}
                onOpenChange={(open) => !open && setPreviewHtml(null)}
            >
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('mail_templates.preview_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('mail_templates.preview_hint')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <p className="text-sm">
                            <span className="text-muted-foreground">
                                {t('mail_templates.subject')}:{' '}
                            </span>
                            {previewSubject}
                        </p>

                        {/*
                            In an iframe, and that is not decoration. A mail
                            template is a page of its own with its own body
                            styles and its own table layout; dropped into this
                            document it would repaint half the settings screen.
                        */}
                        <iframe
                            title={t('mail_templates.preview_title')}
                            srcDoc={previewHtml ?? ''}
                            className="h-[60vh] w-full rounded-md border bg-white"
                        />
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
