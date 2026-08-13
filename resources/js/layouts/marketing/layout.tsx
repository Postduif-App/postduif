import { Link, usePage } from '@inertiajs/react';
import type { ComponentProps, PropsWithChildren } from 'react';

import { Wordmark } from '@/components/marketing/logo';
import { useTranslate } from '@/hooks/use-translate';
import { SOURCE_URL } from '@/lib/postduif';
import { docs, login, register } from '@/routes';
// Wayfinder cannot export a function called `switch`, so it names it
// switchMethod; aliased here so the call site reads like the route does.
import { home as openApp } from '@/routes/chat';
import { switchMethod as switchLocale } from '@/routes/locale';
import type { Auth } from '@/types';

/**
 * Which language the page is in, and the way to the other one.
 *
 * Two links rather than a dropdown: there are exactly two, and a dropdown
 * would hide the one thing somebody who cannot read this page is looking for.
 * The current one is not a link — it is where you already are.
 */
function LanguageChoice({ current }: { current: string }) {
    return (
        <span
            className="flex items-center gap-1.5"
            style={{
                fontFamily: 'var(--pd-mono)',
                fontSize: 12,
                color: 'var(--pd-steen)',
            }}
        >
            {['nl', 'en'].map((locale) =>
                locale === current ? (
                    <span
                        key={locale}
                        aria-current="true"
                        style={{ color: 'var(--pd-inkt)', fontWeight: 600 }}
                    >
                        {locale.toUpperCase()}
                    </span>
                ) : (
                    <Link
                        key={locale}
                        href={switchLocale(locale)}
                        className="pd-plain"
                        style={{ color: 'var(--pd-steen)' }}
                    >
                        {locale.toUpperCase()}
                    </Link>
                ),
            )}
        </span>
    );
}

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
    const { auth, registrationOpen, locale, marketingSite } = usePage<{
        auth: Auth;
        registrationOpen: boolean;
        locale: string;
        marketingSite: boolean;
    }>().props;

    const { t } = useTranslate();

    /*
        Waar het woordmerk heen wijst.

        Op een zelfgehoste installatie bestaat de landingspagina niet — / stuurt
        daar door naar de app of het inlogscherm, zie EnsureMarketingSiteIsShown
        — terwijl deze shell er nog wel staat: de API-pagina blijft bereikbaar,
        want die beschrijft de installatie zelf en niet het product. Het logo
        naar / laten wijzen zou daar een omweg langs een redirect zijn.
    */
    const homeHref = marketingSite
        ? '/'
        : auth.user
          ? openApp.url()
          : login().url;

    return (
        <div className="postduif flex min-h-screen flex-col">
            <header
                className="sticky top-0 z-20"
                style={{
                    borderBottom: '1px solid var(--pd-zand)',
                    background: 'var(--pd-papier)',
                }}
            >
                {/*
                    Wrapping rather than squeezing below ~380px: the wordmark
                    and up to three controls in one non-breaking row is what
                    pushes the narrowest phones into a horizontal scroll.
                */}
                <nav className="mx-auto flex max-w-[1120px] flex-wrap items-center justify-between gap-x-6 gap-y-3 px-6 py-5 sm:px-12">
                    <Link href={homeHref} className="pd-plain">
                        <Wordmark />
                    </Link>

                    <div className="flex items-center gap-3">
                        {/*
                            Only for visitors without an account. A member has
                            been asked this question outright on their profile
                            screen, and that answer outranks the cookie this
                            switcher writes — see HandleLocale. Leaving it here
                            would be offering a control that quietly does
                            nothing, which is worse than not offering one.
                        */}
                        {!auth.user && <LanguageChoice current={locale} />}

                        {/*
                            A plain link rather than a button: the two buttons
                            beside it are the one thing this page is asking for,
                            and a third in the same weight would make it three
                            equal choices. Hidden on the narrowest screens,
                            where the footer still carries it.
                        */}
                        <Link
                            href={docs()}
                            className="pd-plain hidden sm:inline"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 13,
                                color: 'var(--pd-steen)',
                            }}
                        >
                            {t('marketing.nav.api')}
                        </Link>

                        {/*
                            Somebody already signed in gets a way back to their
                            own workspace rather than an invitation to log in
                            again — the commonest visitor to a landing page is
                            the person who built it.
                        */}
                        {auth.user ? (
                            <BrandButton href={openApp.url()}>
                                {t('marketing.nav.to_app')}
                            </BrandButton>
                        ) : (
                            <>
                                {/*
                                    An installation that has closed registration
                                    answers the sign-up page with a 404, so the
                                    call to action becomes the login button —
                                    inviting somebody to begin and then telling
                                    them the page does not exist is worse than
                                    not inviting them.
                                */}
                                <BrandButton
                                    href={login()}
                                    tone={
                                        registrationOpen ? 'outline' : 'solid'
                                    }
                                >
                                    {t('marketing.nav.login')}
                                </BrandButton>
                                {registrationOpen && (
                                    <BrandButton href={register()}>
                                        {t('marketing.nav.start')}
                                    </BrandButton>
                                )}
                            </>
                        )}
                    </div>
                </nav>
            </header>

            <main className="flex-1">{children}</main>

            {/*
                The space above the footer belongs here rather than at the end
                of each page. Every section on these pages carries its own
                padding on top and none underneath, so the last one on any page
                ends flush — and a spacer left to the page is a spacer the next
                page forgets, which is exactly how this got noticed.
            */}
            <footer className="mx-auto w-full max-w-[1120px] px-6 pt-24 pb-24 sm:px-12">
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
                            {t('marketing.footer.tagline')}
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
                        <Link
                            href={docs()}
                            className="pd-plain"
                            style={{ color: 'var(--pd-steen)' }}
                        >
                            {t('marketing.nav.api')}
                        </Link>
                        {/*
                            Hier en niet alleen in de hero: de docs-pagina heeft
                            geen hero, en juist de lezer die daar zit is degene
                            die de broncode wil zien. Een gewone <a>, want dit
                            verlaat de site.
                        */}
                        <a
                            href={SOURCE_URL}
                            target="_blank"
                            rel="noreferrer"
                            className="pd-plain"
                            style={{ color: 'var(--pd-steen)' }}
                        >
                            {t('marketing.footer.source')}
                        </a>
                        <span>{t('marketing.footer.edition')}</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
