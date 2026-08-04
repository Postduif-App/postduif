import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslate } from '@/hooks/use-translate';

/**
 * The languages this application has. Mirrors HandleLocale::SUPPORTED, which is
 * what the endpoint validates against — a language offered here that the
 * validator rejects would be a dropdown that silently refuses.
 *
 * The names stay out of lang/: a language is written in its own language on a
 * list like this, so "Nederlands" is the Dutch and the English word for it.
 */
const LOCALES = [
    { value: 'nl', label: 'Nederlands' },
    { value: 'en', label: 'English' },
];

/** Sent when nothing is chosen. Empty would arrive as "", not as null. */
export const FOLLOW_BROWSER = 'auto';

export function LocaleField({
    value,
    error,
}: {
    /** Null when this member has never picked one. */
    value: string | null;
    error?: string;
}) {
    const { t } = useTranslate();

    return (
        <div className="grid gap-2">
            <Label htmlFor="locale">{t('components.locale.label')}</Label>

            <Select name="locale" defaultValue={value ?? FOLLOW_BROWSER}>
                <SelectTrigger id="locale">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {/*
                        The first option and the default, because it is right
                        for almost everybody: a browser already knows what
                        language its owner reads, and it keeps up when they
                        change their mind.
                    */}
                    <SelectItem value={FOLLOW_BROWSER}>
                        {t('components.locale.follow_browser')}
                    </SelectItem>
                    {LOCALES.map((locale) => (
                        <SelectItem key={locale.value} value={locale.value}>
                            {locale.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <InputError message={error} />
        </div>
    );
}
