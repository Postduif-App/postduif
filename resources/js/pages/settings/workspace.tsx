import { Form, Head } from '@inertiajs/react';

import { AvatarField } from '@/components/avatar-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
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
    return (
        <>
            <Head title="Workspace — algemeen" />

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Algemeen"
                    description={`Geldt voor iedereen in ${workspace.name}`}
                />

                <AvatarField
                    name={workspace.name}
                    avatarUrl={workspace.avatarUrl}
                    uploadUrl={storeAvatar.url()}
                    removeUrl={destroyAvatar.url()}
                    hint="Het logo van deze workspace, zichtbaar voor iedereen die erin zit."
                />

                <Form {...update.form()} options={{ preserveScroll: true }}>
                    {({ processing, errors, recentlySuccessful }) => (
                        <div className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="workspace-name">Naam</Label>
                                <Input
                                    id="workspace-name"
                                    name="name"
                                    defaultValue={workspace.name}
                                    maxLength={60}
                                    className="max-w-sm"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Het webadres blijft{' '}
                                    <code className="rounded bg-muted px-1 font-mono">
                                        /app/{workspace.slug}
                                    </code>{' '}
                                    — dat verandert niet mee, zodat gedeelde
                                    links blijven werken.
                                </p>
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Opslaan
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-sm text-muted-foreground">
                                        Opgeslagen.
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </Form>
            </div>
        </>
    );
}
