import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslate } from '@/hooks/use-translate';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslate();

    return (
        <SettingsSection
            separated
            title={t('account.delete.title')}
            description={t('account.delete.description')}
        >
            {/*
                The warning and the button that acts on it, in one bordered
                block. The heading above stays plain: a page that shouts in red
                from its own title onwards has nothing left to raise its voice
                with by the time you reach the button that actually deletes.
            */}
            <div className="space-y-4 rounded-lg border border-red-200 bg-red-50/60 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="space-y-1 text-red-700 dark:text-red-100">
                    <p className="font-medium">
                        {t('account.delete.warning_title')}
                    </p>
                    <p className="text-sm">{t('account.delete.warning')}</p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            {t('account.delete.button')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            {t('account.delete.question')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('account.delete.explanation')}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            {t('account.delete.password')}
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder={t(
                                                'account.delete.password',
                                            )}
                                            autoComplete="current-password"
                                        />

                                        <InputError message={errors.password} />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                {t('account.delete.cancel')}
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                {t('account.delete.button')}
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </SettingsSection>
    );
}
