import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';

/**
 * The surface every box on this form shares.
 *
 * Lifted from the Input component rather than written afresh, because the
 * complaint it fixes was exactly that they had drifted: a text box drawn
 * transparent over the page's own colour beside a textarea drawn white looked
 * like two different applications on one screen. There is no Textarea component
 * to reach for, so the one line that is Input's look lives here and both use it.
 */
const FIELD_SURFACE =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:aria-invalid:ring-destructive/40';

/**
 * A row somebody picks: one choice, or one of several.
 *
 * The same card the invite dialog uses for picking a role, so that choosing an
 * answer here looks like choosing anything else in this application.
 */
const OPTION_ROW =
    'flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm transition-colors';

/**
 * A question on a form, in the shape PresentForm sends it.
 *
 * `type` is the enum's value rather than a union of the seven cases, because a
 * form built after this file was written may ask something it has never heard
 * of. An unknown type falls back to a single line of text, which is wrong but
 * fillable — better than a question that cannot be answered at all.
 */
export interface FormFieldDefinition {
    key: string;
    type: string;
    label: string;
    hint: string | null;
    required: boolean;
    options: string[];
}

/**
 * A form as both fill screens draw it — the member's and the stranger's.
 *
 * Note what is not here: how many people answered, or who. PresentForm does not
 * send it, on purpose. See its docblock.
 */
export interface FillableForm {
    id: string;
    title: string;
    description: string | null;
    author: string | null;
    state: 'open' | 'closed' | 'expired';
    isFillable: boolean;
    closesAt: string | null;
    fields: FormFieldDefinition[];
}

/**
 * One answer, in the four shapes the seven field types produce.
 *
 * Narrower than "whatever came back" on purpose: a multiple-choice answer is a
 * list and a yes/no is a boolean, and everything else is the string a box
 * holds. The server starts the browser off with exactly these — see
 * SubmitForm::blankAnswers — and normalise() turns them back on the way in.
 */
export type FormAnswerValue = string | number | boolean | string[] | null;

/** What somebody has typed so far, keyed the way the payload is. */
export type FormAnswers = Record<string, FormAnswerValue>;

/**
 * Errors as Laravel sends them back: keyed `answers.<veldsleutel>`, because
 * that is the name the rules were built under. See SubmitForm::rulesFor.
 */
export type FormAnswerErrors = Record<string, string | undefined>;

/**
 * The questions, whatever has to be read before them, and the send button.
 *
 * The answers are held here rather than left to the DOM, because two of the
 * seven types are not a string: a multiple-choice answer is a list and a
 * yes/no is a boolean, and the server said so up front by sending `blank`.
 * Starting from that map is what keeps an untouched tickbox arriving as false
 * instead of missing — see SubmitForm::blankAnswers.
 *
 * `action` is the whole difference between the two doors: the member posts to
 * their workspace's route, the stranger to the token's. Everything after that
 * is the same page.
 */
export function FormAnswerForm({
    fields,
    blank,
    action,
    notice,
}: {
    fields: FormFieldDefinition[];
    blank: FormAnswers;
    action: string;
    /** Read before anybody types, never after. Who sees this, and under what name. */
    notice?: ReactNode;
}) {
    const { t } = useTranslate();
    const { data, setData, post, reset, processing, errors } = useForm<{
        answers: FormAnswers;
    }>({ answers: blank });

    /*
     * Laravel keys these `answers.<veldsleutel>`, which is one level deeper
     * than useForm's own idea of a field name. The cast is only about that
     * depth: the values are still the strings the server sent.
     */
    const fieldErrors = errors as FormAnswerErrors;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        /*
         * Emptied on the way out rather than left standing. A form that may be
         * filled in more than once stays on screen after it is sent, and last
         * week's answers sitting in the boxes is how somebody sends them twice.
         */
        post(action, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            {notice}

            <FormAnswerFields
                fields={fields}
                answers={data.answers}
                errors={fieldErrors}
                disabled={processing}
                onChange={(key, value) =>
                    setData('answers', { ...data.answers, [key]: value })
                }
            />

            {/* The complaint about the payload as a whole, which no field owns. */}
            <InputError message={fieldErrors.answers} />

            <Button type="submit" disabled={processing} className="w-full">
                {processing && <Spinner />}
                {t('forms.fill.send')}
            </Button>
        </form>
    );
}

/** The value of a text-ish answer, whatever the server last put in it. */
function asText(value: FormAnswerValue): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

/** The value of a multiple-choice answer, which is always a list of labels. */
function asList(value: FormAnswerValue): string[] {
    return Array.isArray(value)
        ? value.filter((entry): entry is string => typeof entry === 'string')
        : [];
}

/**
 * Every question on a form, with what has been typed into it.
 *
 * One component for both doors so a question cannot be required on the member's
 * page and optional on the public one — the same reason PresentForm is one
 * presenter for both.
 */
export function FormAnswerFields({
    fields,
    answers,
    errors,
    onChange,
    disabled = false,
}: {
    fields: FormFieldDefinition[];
    answers: FormAnswers;
    errors: FormAnswerErrors;
    onChange: (key: string, value: FormAnswerValue) => void;
    disabled?: boolean;
}) {
    return (
        <div className="flex flex-col gap-6">
            {fields.map((field) => (
                <FormAnswerField
                    key={field.key}
                    field={field}
                    value={answers[field.key]}
                    /*
                     * Both the answer itself and, for a list, whichever entry
                     * tripped. Laravel reports `answers.talen.1` for the entry
                     * and `answers.talen` for the list, and the reader is
                     * looking at one box either way.
                     */
                    error={
                        errors[`answers.${field.key}`] ??
                        errors[`answers.${field.key}.0`]
                    }
                    onChange={onChange}
                    disabled={disabled}
                />
            ))}
        </div>
    );
}

function FormAnswerField({
    field,
    value,
    error,
    onChange,
    disabled,
}: {
    field: FormFieldDefinition;
    value: FormAnswerValue;
    error?: string;
    onChange: (key: string, value: FormAnswerValue) => void;
    disabled: boolean;
}) {
    const id = `field-${field.key}`;
    const hintId = field.hint ? `${id}-hint` : undefined;
    const invalid = error !== undefined;

    /*
     * A tickbox is its own shape: the question is the label beside the box
     * rather than above it, and there is nothing to mark as required — see
     * FormFieldType::rules, where a yes/no is always nullable because "nee" is
     * an answer.
     */
    if (field.type === 'boolean') {
        return (
            <div className="grid gap-2">
                {/*
                    No card around it, unlike the option rows below. Those are a
                    list to choose from and need an edge each; this is a single
                    statement to agree with, and a box drawn around one line was
                    the heaviest thing on the page for the least reason.
                */}
                <label
                    className={cn(
                        'flex cursor-pointer items-start gap-3',
                        disabled && 'cursor-default opacity-60',
                    )}
                >
                    <Checkbox
                        id={id}
                        className="mt-0.5"
                        checked={value === true}
                        disabled={disabled}
                        aria-describedby={hintId}
                        onCheckedChange={(checked) =>
                            onChange(field.key, checked === true)
                        }
                    />
                    <span className="min-w-0">
                        <span className="block text-sm font-medium">
                            {field.label}
                        </span>
                        {field.hint && (
                            <span
                                id={hintId}
                                className="block text-xs text-muted-foreground"
                            >
                                {field.hint}
                            </span>
                        )}
                    </span>
                </label>
                <InputError message={error} />
            </div>
        );
    }

    if (field.type === 'multiple-choice') {
        const chosen = asList(value);

        return (
            <fieldset className="grid gap-2" disabled={disabled}>
                <legend className="mb-1.5 text-sm font-medium">
                    {field.label}
                    {field.required && <Required />}
                </legend>
                {field.hint && <Hint id={hintId} text={field.hint} />}

                <div className="grid gap-2">
                    {field.options.map((option) => (
                        <label
                            key={option}
                            className={cn(
                                OPTION_ROW,
                                chosen.includes(option)
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/50',
                                disabled && 'cursor-default opacity-60',
                            )}
                        >
                            <Checkbox
                                value={option}
                                checked={chosen.includes(option)}
                                aria-describedby={hintId}
                                onCheckedChange={(checked) =>
                                    onChange(
                                        field.key,
                                        // Rebuilt in the order the form asks
                                        // them, not the order they were
                                        // ticked: the answer is read back as a
                                        // sentence, and "Frans, Duits" beside
                                        // a list that says Duits first reads
                                        // as a different answer.
                                        checked === true
                                            ? field.options.filter(
                                                  (entry) =>
                                                      entry === option ||
                                                      chosen.includes(entry),
                                              )
                                            : chosen.filter(
                                                  (entry) => entry !== option,
                                              ),
                                    )
                                }
                            />
                            <span className="min-w-0 flex-1">{option}</span>
                        </label>
                    ))}
                </div>

                <InputError message={error} />
            </fieldset>
        );
    }

    if (field.type === 'choice') {
        const chosen = asText(value);

        return (
            <fieldset className="grid gap-2" disabled={disabled}>
                <legend className="mb-1.5 text-sm font-medium">
                    {field.label}
                    {field.required && <Required />}
                </legend>
                {field.hint && <Hint id={hintId} text={field.hint} />}

                <div className="grid gap-2">
                    {field.options.map((option) => (
                        <label
                            key={option}
                            className={cn(
                                OPTION_ROW,
                                chosen === option
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/50',
                                disabled && 'cursor-default opacity-60',
                            )}
                        >
                            {/*
                                The native control is still here and still what
                                a keyboard and a screen reader use; it is only
                                taken out of the picture, because a browser's
                                own radio button is the one control on this page
                                that cannot be made to match the others.
                            */}
                            <input
                                type="radio"
                                className="peer sr-only"
                                name={id}
                                value={option}
                                checked={chosen === option}
                                aria-describedby={hintId}
                                onChange={() => onChange(field.key, option)}
                            />
                            <span
                                aria-hidden
                                className={cn(
                                    'flex size-4 shrink-0 items-center justify-center rounded-full border border-input shadow-xs transition-colors peer-focus-visible:border-ring peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50',
                                    chosen === option &&
                                        'border-primary bg-primary',
                                )}
                            >
                                {chosen === option && (
                                    <span className="size-1.5 rounded-full bg-primary-foreground" />
                                )}
                            </span>
                            <span className="min-w-0 flex-1">{option}</span>
                        </label>
                    ))}
                </div>

                <InputError message={error} />
            </fieldset>
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>
                {field.label}
                {field.required && <Required />}
            </Label>

            {field.hint && <Hint id={hintId} text={field.hint} />}

            {field.type === 'long-text' ? (
                <textarea
                    id={id}
                    rows={4}
                    value={asText(value)}
                    required={field.required}
                    disabled={disabled}
                    aria-invalid={invalid}
                    aria-describedby={hintId}
                    maxLength={5000}
                    onChange={(event) =>
                        onChange(field.key, event.target.value)
                    }
                    className={cn(FIELD_SURFACE, 'min-h-24 resize-y')}
                />
            ) : (
                <Input
                    id={id}
                    /*
                     * The browser's own number and date boxes rather than a
                     * text field validated afterwards: they are what puts a
                     * calendar and a spinner in front of somebody on a phone,
                     * and what the server validates as numeric and date.
                     */
                    type={
                        field.type === 'number'
                            ? 'number'
                            : field.type === 'date'
                              ? 'date'
                              : 'text'
                    }
                    value={asText(value)}
                    required={field.required}
                    disabled={disabled}
                    aria-invalid={invalid}
                    aria-describedby={hintId}
                    maxLength={field.type === 'short-text' ? 500 : undefined}
                    onChange={(event) =>
                        onChange(field.key, event.target.value)
                    }
                />
            )}

            <InputError message={error} />
        </div>
    );
}

/** The mark on a question that has to be answered, named for a screen reader. */
function Required() {
    const { t } = useTranslate();

    return (
        <span
            className="ml-0.5 text-destructive"
            title={t('forms.screen.field_required')}
        >
            <span aria-hidden>{'*'}</span>
            <span className="sr-only">{t('forms.screen.field_required')}</span>
        </span>
    );
}

function Hint({ id, text }: { id?: string; text: string }) {
    return (
        <p id={id} className="text-xs text-muted-foreground">
            {text}
        </p>
    );
}
