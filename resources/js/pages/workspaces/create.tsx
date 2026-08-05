import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

import WorkspaceCreationController from '@/actions/App/Http/Controllers/WorkspaceCreationController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslate } from '@/hooks/use-translate';

/**
 * Making a workspace of your own.
 *
 * One field, on purpose. The address is worked out from the name on the server
 * — a slug is a thing somebody has to be taught to care about, and the screen
 * straight after signing up is the worst place to teach it. It can be changed
 * later in the workspace settings, by which point there is a reason to.
 *
 * In the auth shell rather than the chat one: there is no sidebar to show,
 * because there is nothing yet to put in it.
 */
export default function CreateWorkspace({ isFirst }: { isFirst: boolean }) {
    const { t } = useTranslate();

    setLayoutProps({
        title: t('workspaces.create.title'),
        // Somebody who has just signed up is being told why they are here;
        // somebody who came looking already knows.
        description: isFirst
            ? t('workspaces.create.description_first')
            : t('workspaces.create.description'),
    });

    return (
        <>
            <Head title={t('workspaces.create.head')} />

            <Form {...WorkspaceCreationController.store.form()}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">
                                {t('workspaces.create.name')}
                            </Label>

                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                maxLength={60}
                                placeholder={t(
                                    'workspaces.create.name_placeholder',
                                )}
                            />

                            <p className="text-xs text-muted-foreground">
                                {t('workspaces.create.name_hint')}
                            </p>

                            <InputError message={errors.name} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="create-workspace-button"
                        >
                            {processing && (
                                <LoaderCircle className="h-4 w-4 animate-spin" />
                            )}
                            {t('workspaces.create.submit')}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}
