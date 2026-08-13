import { Head, setLayoutProps } from '@inertiajs/react';

import { useTranslate } from '@/hooks/use-translate';

/**
 * An account that belongs to no workspace.
 *
 * The end of the road for somebody who has just signed up: workspaces are made
 * in the admin panel, so there is no button on this page that would help them.
 * What there is instead is an explanation, because the alternative — a 404 at
 * the address they were just sent to — reads as a broken account rather than
 * as one that is simply waiting for an invitation.
 */
export default function NoWorkspace() {
    const { t } = useTranslate();

    setLayoutProps({
        title: t('workspaces.none.title'),
        description: t('workspaces.none.description'),
    });

    return (
        <>
            <Head title={t('workspaces.none.head')} />

            <p className="text-sm text-muted-foreground">
                {t('workspaces.none.body')}
            </p>
        </>
    );
}
