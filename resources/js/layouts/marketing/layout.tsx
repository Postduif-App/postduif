import { Link, usePage } from '@inertiajs/react';
import type { ComponentProps, PropsWithChildren } from 'react';

import { Wordmark } from '@/components/marketing/logo';
import { login, register } from '@/routes';
import { home as openApp } from '@/routes/chat';
import type { Auth } from '@/types';

/** The huisstijl's button: Plex Mono, 600, radius 6, ink and yellow. */
function BrandButton({
    href,
    children,
    tone = 'solid',
}: PropsWithChildren<{
    // Taken from Link itself rather than spelled out: the wayfinder helpers
    // hand back a route definition, not a string, and Inertia knows the shape.
    href: ComponentProps<typeof Link>['href'];
    tone?: 'solid' | 'outline';
}>) {
    return (
        <Link href={href} className="pd-button">
            <span
                style={{
                    fontFamily: 'var(--pd-mono)',
                    fontSize: 13,
                    fontWeight: tone === 'solid' ? 600 : 500,
                    display: 'inline-block',
                    padding: '10px 18px',
                    borderRadius: 6,
                    ...(tone === 'solid'
                        ? {
                              background: 'var(--pd-inkt)',
                              color: 'var(--pd-geel)',
                          }
                        : {
                              color: 'var(--pd-inkt)',
                              border: '1px solid #c9c7ba',
                          }),
                }}
            >
                {children}
            </span>
        </Link>
    );
}

/**
 * The shell for the public site, in the huisstijl.
 *
 * Its own layout rather than a variation on the application's, and the huisstijl
 * says why: dark is the app, light is the web and the docs. Two surfaces, so
 * sharing a shell would put every change to the app's chrome on the marketing
 * site.
 *
 * Everything is scoped under `.postduif`. The brand palette is deliberately not
 * the application's — the app is themed per workspace with an accent a
 * beheerder picks, and these colours end where the marketing pages end. See the
 * block at the bottom of app.css.
 *
 * It also has to work signed out, which none of the app's layouts do: auth.user
 * and auth.workspace are both null. Here that is the normal case.
 */
export default function MarketingLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <div className="postduif flex min-h-screen flex-col">
            <header
                className="sticky top-0 z-20"
                style={{
                    borderBottom: '1px solid var(--pd-zand)',
                    background: 'var(--pd-papier)',
                }}
            >
                <nav className="mx-auto flex max-w-[1120px] items-center justify-between gap-6 px-6 py-5 sm:px-12">
                    <Link href="/" className="pd-plain">
                        <Wordmark />
                    </Link>

                    <div className="flex items-center gap-3">
                        {/*
                            Somebody already signed in gets a way back to their
                            own workspace rather than an invitation to log in
                            again — the commonest visitor to a landing page is
                            the person who built it.
                        */}
                        {auth.user ? (
                            <BrandButton href={openApp.url()}>
                                Naar de app
                            </BrandButton>
                        ) : (
                            <>
                                <BrandButton href={login()} tone="outline">
                                    Inloggen
                                </BrandButton>
                                <BrandButton href={register()}>
                                    Beginnen
                                </BrandButton>
                            </>
                        )}
                    </div>
                </nav>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="mx-auto w-full max-w-[1120px] px-6 pb-24 sm:px-12">
                <div
                    className="flex flex-wrap items-center justify-between gap-10 p-8 sm:p-14"
                    style={{ background: 'var(--pd-inkt)', borderRadius: 12 }}
                >
                    <div>
                        <Wordmark on="ink" />
                        <p
                            className="mt-4 max-w-[44ch]"
                            style={{
                                fontFamily: 'var(--pd-sans)',
                                fontSize: 15,
                                lineHeight: 1.55,
                                color: '#b6b4a5',
                            }}
                        >
                            Een werkplek voor gesprekken, het werk dat eruit
                            volgt en de bestanden die erbij horen.
                        </p>
                    </div>

                    <div
                        className="flex flex-col gap-2"
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 13,
                            color: 'var(--pd-steen)',
                        }}
                    >
                        <span style={{ color: '#b6b4a5' }}>postduif</span>
                        <span>augustus 2026</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
