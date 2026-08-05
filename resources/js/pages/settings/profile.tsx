import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { AvatarField } from '@/components/avatar-field';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { LocaleField } from '@/components/locale-field';
import { TimezoneField } from '@/components/timezone-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslate } from '@/hooks/use-translate';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

/**
 * The same limit the validator holds to. Repeated here rather than sent down,
 * because what it buys is a counter that answers before the round trip — and a
 * maxLength that disagreed with the rule would only ever be the smaller of the
 * two, which is a stricter field rather than a broken one.
 */
const BIO_LIMIT = 280;

/**
 * A few lines beside your name.
 *
 * Uncontrolled like the fields around it — the form reads the DOM on submit —
 * except for the count, which needs to move as you type. Hence state that
 * holds a length rather than the text: nothing here has any business owning
 * what you wrote.
 */
function BioField({ value, error }: { value: string | null; error?: string }) {
    const { t, tChoice } = useTranslate();

    const [length, setLength] = useState(value?.length ?? 0);

    return (
        <div className="grid gap-2">
            <Label htmlFor="bio">{t('settings.profile.bio')}</Label>

            <textarea
                id="bio"
                name="bio"
                rows={3}
                maxLength={BIO_LIMIT}
                defaultValue={value ?? ''}
                onChange={(event) => setLength(event.target.value.length)}
                placeholder={t('settings.profile.bio_placeholder')}
                className="mt-1 block w-full resize-none rounded-md border bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
            />

            <div className="flex items-start justify-between gap-4">
                <p className="text-xs text-muted-foreground">
                    {t('settings.profile.bio_hint')}
                </p>

                {/*
                    Only once it is worth knowing. A counter standing at 280
                    beside an empty box reads as a demand for 280 characters.
                */}
                {length > BIO_LIMIT / 2 && (
                    <p className="shrink-0 text-xs text-muted-foreground tabular-nums">
                        {tChoice(
                            'settings.profile.bio_remaining',
                            BIO_LIMIT - length,
                        )}
                    </p>
                )}
            </div>

            <InputError className="mt-2" message={error} />
        </div>
    );
}

export default function Profile({
    mustVerifyEmail,
    status,
    timezones,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    /** Every zone PHP knows — the same set the validator accepts. */
    timezones: string[];
}) {
    const { auth } = usePage<PageProps>().props;
    const { t } = useTranslate();

    return (
        <>
            <Head title={t('settings.profile.title')} />

            <h1 className="sr-only">{t('settings.profile.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.profile.title')}
                    description={t('settings.profile.description')}
                />

                <AvatarField name={auth.user.name} avatarUrl={auth.avatarUrl} />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('settings.profile.name')}
                                </Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder={t(
                                        'settings.profile.name_placeholder',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('settings.profile.email')}
                                </Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder={t(
                                        'settings.profile.email_placeholder',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            <BioField
                                value={auth.user.bio}
                                error={errors.bio}
                            />

                            <TimezoneField
                                timezones={timezones}
                                value={auth.user.timezone}
                                error={errors.timezone}
                            />

                            <LocaleField
                                value={auth.user.locale}
                                error={errors.locale}
                            />

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            {t('settings.profile.unverified')}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                {t('settings.profile.resend')}
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                {t('settings.profile.resent')}
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {t('settings.actions.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}
