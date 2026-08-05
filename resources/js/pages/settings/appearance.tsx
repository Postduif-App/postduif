import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import { SettingsSection } from '@/components/settings-section';
import { useTranslate } from '@/hooks/use-translate';

export default function Appearance() {
    const { t } = useTranslate();

    return (
        <>
            <Head title={t('settings.appearance.title')} />

            <h1 className="sr-only">{t('settings.appearance.title')}</h1>

            <SettingsSection
                title={t('settings.appearance.title')}
                description={t('settings.appearance.description')}
            >
                <AppearanceTabs />
            </SettingsSection>
        </>
    );
}
