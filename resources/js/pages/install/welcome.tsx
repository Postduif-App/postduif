import { Form, Head } from '@inertiajs/react';
import { KeyRound, ShieldAlert } from 'lucide-react';

import InstallController from '@/actions/App/Http/Controllers/InstallController';
import InputError from '@/components/input-error';
import { Wordmark } from '@/components/marketing/logo';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';

/**
 * The one screen a platform has before it is a platform.
 *
 * Its own shell rather than the auth card every other signed-out screen wears.
 * That card is built for somebody who arrives at an application that exists —
 * one field, one button, a way back to the public site. Here there is no
 * application yet and nowhere to go back to, and what the reader most needs is
 * not a form but an answer to "what am I about to end up with". So the left
 * half explains and the right half asks, and the wordmark links nowhere,
 * because every other address on this installation redirects straight back
 * here anyway.
 *
 * Ink on the left with the 48px grid, exactly as the marketing hero does it:
 * this is the same first impression, and a second visual language for the
 * screen in between would be one nobody chose.
 */
export default function Install({ passwordRules }: { passwordRules: string }) {
    const { t } = useTranslate();

    const steps = [
        {
            key: 'account',
            title: t('install.steps.account.title'),
            body: t('install.steps.account.body'),
        },
        {
            key: 'workspace',
            title: t('install.steps.workspace.title'),
            body: t('install.steps.workspace.body'),
        },
        {
            key: 'rest',
            title: t('install.steps.rest.title'),
            body: t('install.steps.rest.body'),
        },
    ];

    return (
        <div className="postduif pd-themed min-h-svh lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,520px)]">
            <Head title={t('install.head')} />

            {/*
                The explaining half. Ink whatever the theme says — like the
                marketing hero, and unlike the form beside it, which follows the
                light or dark the browser asked for.
            */}
            <section
                className="relative overflow-hidden px-6 py-16 sm:px-12 lg:flex lg:flex-col lg:justify-center lg:py-20"
                style={{
                    background: 'var(--pd-inkt)',
                    color: 'var(--pd-papier)',
                }}
            >
                <div
                    className="pointer-events-none absolute inset-0"
                    style={{
                        opacity: 0.06,
                        backgroundImage:
                            'linear-gradient(#F7F6F1 1px, transparent 1px), linear-gradient(90deg, #F7F6F1 1px, transparent 1px)',
                        backgroundSize: '48px 48px',
                    }}
                />

                <div className="relative mx-auto w-full max-w-[560px] lg:mr-0 lg:ml-auto lg:pr-14">
                    <div className="mb-10">
                        <Wordmark on="ink" />
                    </div>

                    <div
                        className="mb-7 inline-flex items-center gap-2.5 rounded-full px-3 py-1.5"
                        style={{
                            border: '1px solid #3a3930',
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 12,
                            color: '#b6b4a5',
                        }}
                    >
                        <span
                            style={{
                                width: 7,
                                height: 7,
                                borderRadius: '50%',
                                background: 'var(--pd-geel)',
                            }}
                        />
                        {t('install.eyebrow')}
                    </div>

                    <h1
                        className="m-0 mb-6 max-w-[14ch] text-[38px] sm:text-[52px]"
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontWeight: 600,
                            lineHeight: 0.98,
                            letterSpacing: '-0.045em',
                            textWrap: 'balance',
                        }}
                    >
                        {t('install.headline')}
                    </h1>

                    <p
                        className="m-0 mb-12 max-w-[46ch]"
                        style={{
                            fontSize: 17,
                            lineHeight: 1.6,
                            color: '#b6b4a5',
                            textWrap: 'pretty',
                        }}
                    >
                        {t('install.intro')}
                    </p>

                    {/*
                        Numbered rather than bulleted: these are not three
                        features, they are the order the next two minutes go in,
                        and the third one deliberately happens after this screen
                        is gone.
                    */}
                    <ol className="m-0 flex list-none flex-col gap-7 p-0">
                        {steps.map((step, index) => (
                            <li key={step.key} className="flex gap-4">
                                <span
                                    className="flex shrink-0 items-center justify-center rounded-[6px]"
                                    style={{
                                        width: 30,
                                        height: 30,
                                        border: '1px solid #3a3930',
                                        fontFamily: 'var(--pd-mono)',
                                        fontSize: 13,
                                        fontWeight: 600,
                                        color: 'var(--pd-geel)',
                                    }}
                                >
                                    {index + 1}
                                </span>

                                <div>
                                    <p
                                        className="m-0 mb-1"
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 14,
                                            fontWeight: 600,
                                            letterSpacing: '-0.02em',
                                        }}
                                    >
                                        {step.title}
                                    </p>
                                    <p
                                        className="m-0 max-w-[44ch]"
                                        style={{
                                            fontSize: 14,
                                            lineHeight: 1.55,
                                            color: '#b6b4a5',
                                            textWrap: 'pretty',
                                        }}
                                    >
                                        {step.body}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            {/* The asking half. */}
            <section className="flex items-center px-6 py-14 sm:px-10 lg:py-20">
                <div className="mx-auto w-full max-w-[400px]">
                    <Form
                        {...InstallController.store.form()}
                        resetOnSuccess={['password', 'password_confirmation']}
                        disableWhileProcessing
                        className="flex flex-col gap-8"
                    >
                        {({ processing, errors }) => (
                            <>
                                <fieldset className="m-0 flex flex-col gap-5 border-0 p-0">
                                    <legend className="mb-1 flex items-center gap-2 p-0">
                                        <KeyRound
                                            className="size-4"
                                            style={{ color: 'var(--pd-steen)' }}
                                        />
                                        <span
                                            style={{
                                                fontFamily: 'var(--pd-mono)',
                                                fontSize: 13,
                                                fontWeight: 600,
                                                letterSpacing: '-0.02em',
                                            }}
                                        >
                                            {t('install.form.title')}
                                        </span>
                                    </legend>

                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t('install.form.name')}
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            type="text"
                                            required
                                            autoFocus
                                            tabIndex={1}
                                            autoComplete="name"
                                            placeholder={t(
                                                'install.form.name_placeholder',
                                            )}
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">
                                            {t('install.form.email')}
                                        </Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            tabIndex={2}
                                            autoComplete="email"
                                            placeholder="email@example.com"
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            {t('install.form.password')}
                                        </Label>
                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            required
                                            tabIndex={3}
                                            autoComplete="new-password"
                                            /*
                                                What the server will ask of it,
                                                read off Password::defaults() —
                                                so a password manager offers one
                                                that passes rather than one the
                                                form then rejects.
                                            */
                                            passwordrules={passwordRules}
                                            placeholder="••••••••"
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">
                                            {t(
                                                'install.form.password_confirmation',
                                            )}
                                        </Label>
                                        <PasswordInput
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            required
                                            tabIndex={4}
                                            autoComplete="new-password"
                                            passwordrules={passwordRules}
                                            placeholder="••••••••"
                                        />
                                        <InputError
                                            message={
                                                errors.password_confirmation
                                            }
                                        />
                                    </div>
                                </fieldset>

                                <fieldset className="m-0 flex flex-col gap-2 border-0 p-0">
                                    <legend className="mb-1 p-0">
                                        <span
                                            style={{
                                                fontFamily: 'var(--pd-mono)',
                                                fontSize: 13,
                                                fontWeight: 600,
                                                letterSpacing: '-0.02em',
                                            }}
                                        >
                                            {t('install.form.workspace_title')}
                                        </span>
                                    </legend>

                                    <Label htmlFor="workspace">
                                        {t('install.form.workspace')}
                                    </Label>
                                    <Input
                                        id="workspace"
                                        name="workspace"
                                        type="text"
                                        required
                                        tabIndex={5}
                                        maxLength={60}
                                        placeholder={t(
                                            'install.form.workspace_placeholder',
                                        )}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('install.form.workspace_hint')}
                                    </p>
                                    <InputError message={errors.workspace} />
                                </fieldset>

                                <Button
                                    type="submit"
                                    className="w-full"
                                    tabIndex={6}
                                    disabled={processing}
                                    data-test="install-button"
                                >
                                    {processing && <Spinner />}
                                    {processing
                                        ? t('install.form.submitting')
                                        : t('install.form.submit')}
                                </Button>

                                {/*
                                    Said out loud rather than left implied. Until
                                    this form is submitted the address hands
                                    platform-wide rights to whoever opens it, and
                                    the person who has just deployed a server is
                                    exactly the person who might wander off and
                                    finish it tomorrow.
                                */}
                                <p
                                    className="m-0 flex gap-2.5 rounded-[6px] p-3.5"
                                    style={{
                                        border: '1px solid var(--pd-zand)',
                                        fontSize: 13,
                                        lineHeight: 1.5,
                                        color: 'var(--pd-steen)',
                                        textWrap: 'pretty',
                                    }}
                                >
                                    <ShieldAlert className="mt-px size-4 shrink-0" />
                                    {t('install.warning')}
                                </p>
                            </>
                        )}
                    </Form>
                </div>
            </section>
        </div>
    );
}
