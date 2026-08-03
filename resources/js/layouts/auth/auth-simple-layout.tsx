import { Link } from '@inertiajs/react';
import { Wordmark } from '@/components/marketing/logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * The shell for every screen somebody meets from outside the application.
 *
 * Logging in, accepting an invitation, downloading files somebody sent, filling
 * in a request for secrets — all of them are the first thing a person sees of
 * Postduif, and several are the *only* thing: a customer who fills in a
 * password may never open the app at all.
 *
 * So it wears the huisstijl rather than the application's own look. The
 * `.postduif` class carries both the brand palette and a remapping of the
 * shadcn tokens (see the bottom of app.css), which is what makes the Button and
 * Input on these pages come out in ink and sand without a single component
 * being rewritten.
 *
 * Note it stops here. Everything behind the login is themed per workspace with
 * an accent a beheerder picks, and that is a different job from carrying a
 * brand.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
    wide = false,
}: AuthLayoutProps) {
    return (
        <div className="postduif flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className={wide ? 'w-full max-w-2xl' : 'w-full max-w-sm'}>
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-5">
                        {/*
                            The way back to the public site, which is where
                            somebody who landed here by accident wants to go.
                        */}
                        <Link href={home()} className="pd-plain">
                            <Wordmark />
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1
                                style={{
                                    fontFamily: 'var(--pd-mono)',
                                    fontSize: 20,
                                    fontWeight: 600,
                                    letterSpacing: '-0.03em',
                                    margin: 0,
                                }}
                            >
                                {title}
                            </h1>
                            <p
                                className="text-center"
                                style={{
                                    fontSize: 14,
                                    lineHeight: 1.55,
                                    color: 'var(--pd-steen)',
                                }}
                            >
                                {description}
                            </p>
                        </div>
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}
