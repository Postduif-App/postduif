import { Head, setLayoutProps } from '@inertiajs/react';
import { EyeOff } from 'lucide-react';

import type {
    FillableForm,
    FormAnswers,
} from '@/components/forms/form-answer-fields';
import { FormAnswerForm } from '@/components/forms/form-answer-fields';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { submit } from '@/routes/forms/public';
import type { TranslationKey } from '@/types/translations';

interface PublicFormProps {
    form: FillableForm;
    blank: FormAnswers;
    /** The whole permission this visitor has. It is also the only address. */
    token: string;
    /** Always true here, and the page says so out loud before anybody types. */
    anonymous: true;
}

/**
 * Why the link leads nowhere to type.
 *
 * Two reasons rather than one, exactly as the card in a channel distinguishes
 * them: somebody stopped this form, or the moment it was waiting for passed. A
 * withdrawn link never gets this far — that is a 404, so that an old address
 * stops being evidence that anything was ever there.
 */
const SHUT: Record<string, TranslationKey> = {
    closed: 'forms.fill.closed',
    expired: 'forms.fill.expired',
};

/**
 * Filling in a form from outside, with nothing but a link.
 *
 * Whoever reads this may have no account here and no idea what this workspace
 * is, so the page shows exactly what PresentForm sent and not one fact more —
 * no workspace name, no members, no sign of who else answered. What it does say
 * first, above the questions rather than under the send button, is that no name
 * goes with the answers. Somebody typing why they need leave deserves to know
 * that before they start rather than after they have sent it.
 */
export default function PublicForm({ form, blank, token }: PublicFormProps) {
    const { t } = useTranslate();
    const formats = useFormats();
    const open = form.state === 'open';

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

                {!open && (
                    <p className="rounded-lg border border-destructive/40 p-3 text-sm text-muted-foreground">
                        {t(SHUT[form.state])}
                    </p>
                )}

                {open && form.fields.length === 0 && (
                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                        {t('forms.fill.empty')}
                    </p>
                )}

                {open && form.isFillable && form.fields.length > 0 && (
                    <FormAnswerForm
                        fields={form.fields}
                        blank={blank}
                        action={submit.url(token)}
                        notice={
                            /*
                             * Above the questions and not below them. A promise
                             * about what is being collected is only worth
                             * anything while there is still time to walk away.
                             */
                            <p className="flex items-start gap-2 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm">
                                <EyeOff className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <span>{t('forms.fill.anonymous_notice')}</span>
                            </p>
                        }
                    />
                )}

                {form.closesAt && open && (
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
