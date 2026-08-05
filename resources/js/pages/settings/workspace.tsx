import { Form, Head } from '@inertiajs/react';

import { AvatarField } from '@/components/avatar-field';
import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { update } from '@/routes/workspace';
import {
    destroy as destroyAvatar,
    store as storeAvatar,
} from '@/routes/workspace/avatar';

interface WorkspaceSettingsProps {
    workspace: {
        name: string;
        slug: string;
        /** The logo, or null when none is set. */
        avatarUrl: string | null;
    };
}

export default function WorkspaceSettings({
    workspace,
}: WorkspaceSettingsProps) {
    const { t } = useTranslate();

    return (
        <>
            <Head title={t('settings.workspace.head')} />

            <SettingsSection
                title={t('settings.workspace.title')}
                description={t('settings.workspace.description', {
                    workspace: workspace.name,
                })}
            >
                <AvatarField
                    name={workspace.name}
                    avatarUrl={workspace.avatarUrl}
                    uploadUrl={storeAvatar.url()}
                    removeUrl={destroyAvatar.url()}
                    hint={t('settings.workspace.logo_hint')}
                />

                <Form {...update.form()} options={{ preserveScroll: true }}>
                    {({ processing, errors, recentlySuccessful }) => (
                        <div className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="workspace-name">
                                    {t('settings.workspace.name')}
                                </Label>
                                <Input
                                    id="workspace-name"
                                    name="name"
                                    defaultValue={workspace.name}
                                    maxLength={60}
                                    className="max-w-sm"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('settings.workspace.address_lead')}{' '}
                                    <code className="rounded bg-muted px-1 font-mono">
                                        {`/app/${workspace.slug}`}
                                    </code>{' '}
                                    {t('settings.workspace.address_tail')}
                                </p>
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex items-center gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('settings.actions.save')}
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('settings.actions.saved')}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </Form>
            </SettingsSection>
        </>
    );
}
