import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';

import type {
    FillableForm,
    FormAnswers,
} from '@/components/forms/form-answer-fields';
import { FormAnswerForm } from '@/components/forms/form-answer-fields';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { index as chatIndex } from '@/routes/chat';
import { submit } from '@/routes/chat/forms';
import type { TranslationKey } from '@/types/translations';

interface FillProps {
    form: FillableForm;
    /** The empty answer per question, in the shape the server wants it back. */
    blank: FormAnswers;
    /** Whether this member may send one in right now. */
    canSubmit: boolean;
    /** Whether they already did. Not the same thing: a form may allow seconds. */
    hasSubmitted: boolean;
    workspaceSlug: string;
    /**
     * Always false on this page — the answers carry the member's name. The
     * public page is the one where this is true, and it is the whole difference
     * between the two.
     */
    anonymous: false;
}

/**
 * Filling in a form from inside the workspace.
 *
 * A page rather than a card that unfolds in the channel: a form is a page of
 * questions, and answering it is a thing somebody sits down to do. It is also
 * the address the card in the conversation points at, so the link and the page
 * are the same fact.
 *
 * Nothing here says how many other people answered, because nothing sent it.
 * That is the maker's business — see PresentForm.
 */
export default function FormFill({
    form,
    blank,
    canSubmit,
    hasSubmitted,
    workspaceSlug,
}: FillProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    setLayoutProps({
        title: t('forms.fill.title'),
        description: form.title,
    });

    return (
        <>
            <Head title={form.title} />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    {form.author && (
                        <p className="text-sm text-muted-foreground">
                            {t('forms.fill.author', { name: form.author })}
                        </p>
                    )}
                    <p className="text-lg font-medium">{form.title}</p>
                </div>

                {form.description && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm whitespace-pre-line">
                        {form.description}
                    </p>
                )}

                {canSubmit ? (
                    <FormAnswerForm
                        fields={form.fields}
                        blank={blank}
                        action={submit.url({
                            workspace: workspaceSlug,
                            form: form.id,
                        })}
                        notice={
                            /*
                             * Said before they type, not after. Somebody who
                             * assumes a form is anonymous will write a
                             * different answer than somebody who knows their
                             * name goes with it.
                             */
                            form.author ? (
                                <p className="flex items-start gap-2 rounded-lg border p-3 text-xs text-muted-foreground">
                                    <ClipboardList className="mt-0.5 size-4 shrink-0" />
                                    <span>
                                        {t('forms.fill.named_notice', {
                                            name: form.author,
                                        })}
                                    </span>
                                </p>
                            ) : undefined
                        }
                    />
                ) : (
                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                        {t(reasonFor({ form, hasSubmitted }))}
                    </p>
                )}

                {/*
                    The way out.

                    This page runs in the one-card shell with no navigation
                    around it, the same as answering a secret request — which is
                    right for somebody who arrived by link and wrong for the
                    member who clicked a card two seconds ago and now has
                    nowhere to go but the back button.
                */}
                <p className="text-center text-xs">
                    <Link
                        href={chatIndex.url({ workspace: workspaceSlug })}
                        className="text-muted-foreground hover:underline"
                    >
                        {t('forms.fill.back')}
                    </Link>
                </p>

                {form.closesAt && form.state === 'open' && (
                    <p className="text-center text-xs text-muted-foreground">
                        {t('forms.fill.closes_on', {
                            date: formats.longDate.format(
                                new Date(form.closesAt),
                            ),
                        })}
                    </p>
                )}
            </div>
        </>
    );
}

/**
 * Why there is no form to fill in, in the order the reader cares about.
 *
 * Closed comes first because it is the dead end: whether you answered a form
 * that is shut makes no difference to what you can do next. Having answered
 * comes before emptiness because it is the likelier reason by far, and the last
 * one is the maker's mistake rather than the reader's.
 */
function reasonFor({
    form,
    hasSubmitted,
}: {
    form: FillableForm;
    hasSubmitted: boolean;
}): TranslationKey {
    if (form.state === 'expired') {
        return 'forms.fill.expired';
    }

    if (form.state !== 'open') {
        return 'forms.fill.closed';
    }

    if (hasSubmitted) {
        return 'forms.fill.already';
    }

    if (form.fields.length === 0) {
        return 'forms.fill.empty';
    }

    return 'forms.fill.closed';
}
