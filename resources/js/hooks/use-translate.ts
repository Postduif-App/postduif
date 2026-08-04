import { usePage } from '@inertiajs/react';

import type { Replacements, TranslationLines } from '@/lib/translate';
import { choose, translate } from '@/lib/translate';
import type { TranslationKey } from '@/types/translations';

/**
 * The lines for the language this page was rendered in.
 *
 * Shared through Inertia rather than fetched or bundled: the server already
 * decided the language for this request — see HandleLocale — and anything that
 * decided again on the client could disagree with the half of the page that
 * came pre-rendered.
 */
export function useTranslate() {
    const { translations } = usePage<{ translations: TranslationLines }>()
        .props;

    return {
        /** One line. The key is checked against what lang/nl actually holds. */
        t: (key: TranslationKey, replacements?: Replacements) =>
            translate(translations, key, replacements),

        /** One line, in the wording that fits the count. */
        tChoice: (
            key: TranslationKey,
            count: number,
            replacements?: Replacements,
        ) => choose(translations, key, count, replacements),
    };
}
