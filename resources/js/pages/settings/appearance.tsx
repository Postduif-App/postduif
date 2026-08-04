import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { useTranslate } from '@/hooks/use-translate';

export default function Appearance() {
    const { t } = useTranslate();

    return (
        <>
            <Head title={t('settings.appearance.title')} />

            <h1 className="sr-only">{t('settings.appearance.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.appearance.title')}
                    description={t('settings.appearance.description')}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}
