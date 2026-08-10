import { Head, Link, usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';

import { DoveMark } from '@/components/marketing/logo';
import {
    Card,
    DescribedList,
    Note,
    SectionHead,
} from '@/components/marketing/prose';
import type { Described } from '@/components/marketing/prose';
import { useTranslate } from '@/hooks/use-translate';
import { SOURCE_URL } from '@/lib/postduif';
import { login, register } from '@/routes';

interface Feature {
    key: string;
    label: string;
    description: string;
    /** False for the three that only exist once somebody switches them on. */
    onByDefault: boolean;
}

interface Role {
    value: string;
    label: string;
    /**
     * A column on the role rather than one of the abilities below, which is why
     * it is the one answer that gets its own row and its own footnote.
     */
    canBrowseWorkspace: boolean;
    /** The rights this role is seeded with, as WorkspaceAbility spells them. */
    abilities: string[];
}

interface Ability {
    value: string;
    label: string;
}

interface HomeProps {
    features: Feature[];
    roles: Role[];
    abilities: Ability[];
    channelSettings: {
        layout: Described[];
        posting: Described[];
        tickets: Described[];
    };
    workflow: { triggers: Described[]; actions: Described[] };
    token: {
        endpoints: { method: string; path: string }[];
        tools: { name: string; description: string }[];
    };
}

/**
 * The three shapes the permission table is drawn with.
 *
 * Pulled out of the JSX because the table now builds its rows and its columns
 * from two arrays rather than writing them out, and a style object repeated
 * inside two nested maps is the kind of thing that drifts apart one cell at a
 * time.
 */
const headCell: CSSProperties = {
    fontFamily: 'var(--pd-mono)',
    fontSize: 11,
    letterSpacing: '0.08em',
    color: '#8b8a7b',
    fontWeight: 400,
    textTransform: 'uppercase',
};

const bodyRow: CSSProperties = {
    borderBottom: '1px solid var(--pd-zand)',
};

/** The row's own heading — a right, in the words the settings screen uses. */
const rowHead: CSSProperties = {
    fontFamily: 'var(--pd-mono)',
    fontSize: 13,
    fontWeight: 600,
};

function Mark({ on }: { on: boolean }) {
    const { t } = useTranslate();

    return (
        <span
            // A dot and a dash carry the answer visually; a screen reader needs
            // the word, and the word is not the same in both languages.
            aria-label={
                on
                    ? t('marketing.home.roles.yes')
                    : t('marketing.home.roles.no')
            }
            style={{
                fontFamily: 'var(--pd-mono)',
                fontSize: 13,
                color: on ? 'var(--pd-inkt)' : '#c9c7ba',
            }}
        >
            {on ? '●' : '—'}
        </span>
    );
}

/**
 * The landing page.
 *
 * Two rules pull against each other here and both are kept. The look comes from
 * the huisstijl — Plex Mono for structure, Plex Sans for reading, ink and
 * yellow, radius 6 and 10, no shadows. The *claims* come from the application:
 * every capability below was read off the feature classes by the server.
 *
 * The huisstijl's own example page advertises Matrix federation, end-to-end
 * encryption, a single binary and a curl installer. None of that is true of
 * this codebase, so none of it is here — which is exactly what the brief asked
 * for. Taking the design without the copy is the whole job.
 */
export default function MarketingHome({
    features,
    roles,
    abilities,
    channelSettings,
    workflow,
    token,
}: HomeProps) {
    const optIn = features.filter((feature) => !feature.onByDefault);

    // Shared by HandleInertiaRequests rather than passed by the controller: the
    // navbar above this page reads the same flag, and one of them being a page
    // prop is how the two would come to disagree.
    const { registrationOpen } = usePage<{ registrationOpen: boolean }>().props;

    const { t, tChoice } = useTranslate();

    return (
        <>
            <Head title={t('marketing.home.head')} />

            {/*
                Hero, on ink, with the 48px grid the huisstijl uses.

                The ink and the grid run edge to edge, so the width is held one
                level in — see the div below. Nothing horizontal belongs on the
                section itself: padding here would inset the background rather
                than the words.
            */}
            <section
                className="relative overflow-hidden pt-24"
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

                {/*
                    The same 1120 and the same padding as the navbar and every
                    section below, on one element rather than two. Box-sizing is
                    border-box, so a max-width that sits above the padding is a
                    different width from one that sits beside it: this used to
                    give the hero the full 1120 while everything else got 1120
                    less its padding, and the words hung out past the wordmark.
                */}
                <div className="relative mx-auto max-w-[1120px] px-6 sm:px-12">
                    <div
                        className="mb-8 inline-flex items-center gap-2.5 rounded-full px-3 py-1.5"
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
                        {t('marketing.home.eyebrow')}
                    </div>

                    <h1
                        className="m-0 mb-7 max-w-[16ch] text-[44px] sm:text-[76px]"
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontWeight: 600,
                            lineHeight: 0.98,
                            letterSpacing: '-0.045em',
                            textWrap: 'balance',
                        }}
                    >
                        {t('marketing.home.headline')}
                    </h1>

                    <p
                        className="m-0 mb-10 max-w-[56ch]"
                        style={{
                            fontSize: 19,
                            lineHeight: 1.6,
                            color: '#b6b4a5',
                            textWrap: 'pretty',
                        }}
                    >
                        {t('marketing.home.intro')}
                    </p>

                    <div className="mb-16 flex flex-wrap items-center gap-3.5">
                        {/*
                            Where this points depends on whether the door is
                            open. An installation that closed registration
                            answers /register with a 404, and the button that
                            says "Beginnen" is exactly the one somebody presses
                            first — so it becomes the way in that does work,
                            rather than being taken away and leaving a landing
                            page with nothing to press. The button in the navbar
                            follows the same rule; see the marketing layout.
                        */}
                        <Link
                            href={registrationOpen ? register() : login()}
                            className="pd-button"
                        >
                            <span
                                style={{
                                    fontFamily: 'var(--pd-mono)',
                                    fontSize: 14,
                                    fontWeight: 600,
                                    background: 'var(--pd-geel)',
                                    color: 'var(--pd-inkt)',
                                    padding: '14px 22px',
                                    borderRadius: 6,
                                    display: 'inline-block',
                                }}
                            >
                                {registrationOpen
                                    ? t('marketing.home.cta_start')
                                    : t('marketing.home.cta_login')}
                            </span>
                        </Link>
                        <span
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 13,
                                color: 'var(--pd-steen)',
                            }}
                        >
                            {tChoice(
                                'marketing.home.feature_count',
                                features.length,
                            )}{' '}
                            ·{' '}
                            {tChoice(
                                'marketing.home.opt_in_count',
                                optIn.length,
                            )}
                        </span>

                        {/*
                            Een gewone <a> en geen Inertia-Link: dit gaat de
                            site uit, en Link zou GitHub als Inertia-antwoord
                            proberen op te halen. In geel, want dat is de enige
                            kleur op de inkt die naast de knop nog leesbaar is
                            zonder een tweede knop te lijken.
                        */}
                        <a
                            href={SOURCE_URL}
                            target="_blank"
                            rel="noreferrer"
                            className="pd-plain"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 13,
                                color: 'var(--pd-geel)',
                                textDecoration: 'underline',
                                textUnderlineOffset: 3,
                            }}
                        >
                            {t('marketing.home.source')}
                        </a>
                    </div>

                    <div className="h-24" />
                </div>
            </section>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="01"
                    title={t('marketing.home.features.title')}
                    lead={t('marketing.home.features.lead')}
                />

                <ul className="grid list-none grid-cols-1 gap-6 p-0 sm:grid-cols-2">
                    {features.map((feature) => (
                        <li key={feature.key}>
                            <Card>
                                <div className="mb-3 flex items-center gap-2.5">
                                    <span style={{ color: 'var(--pd-inkt)' }}>
                                        <DoveMark size={16} />
                                    </span>
                                    <span
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 15,
                                            fontWeight: 600,
                                        }}
                                    >
                                        {feature.label}
                                    </span>
                                    {!feature.onByDefault && (
                                        <span
                                            className="ml-auto"
                                            style={{
                                                fontFamily: 'var(--pd-mono)',
                                                fontSize: 11,
                                                background: 'var(--pd-geel)',
                                                color: 'var(--pd-inkt)',
                                                padding: '4px 8px',
                                                borderRadius: 4,
                                                fontWeight: 600,
                                            }}
                                        >
                                            {t(
                                                'marketing.home.features.off_by_default',
                                            )}
                                        </span>
                                    )}
                                </div>
                                <p
                                    className="m-0"
                                    style={{
                                        fontSize: 15,
                                        lineHeight: 1.6,
                                        color: '#3d3c33',
                                    }}
                                >
                                    {feature.description}
                                </p>
                            </Card>
                        </li>
                    ))}
                </ul>
            </div>

            {optIn.length > 0 && (
                <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                    <SectionHead
                        number="02"
                        title={t('marketing.home.opt_in.title')}
                        lead={t('marketing.home.opt_in.lead')}
                    />

                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                        {optIn.map((feature, index) => (
                            <Card key={feature.key}>
                                <div
                                    className="mb-3"
                                    style={{
                                        fontFamily: 'var(--pd-mono)',
                                        fontSize: 12,
                                        color: '#8b8a7b',
                                    }}
                                >
                                    {String(index + 1).padStart(2, '0')}
                                </div>
                                <div
                                    className="mb-2"
                                    style={{
                                        fontFamily: 'var(--pd-mono)',
                                        fontSize: 16,
                                        fontWeight: 600,
                                    }}
                                >
                                    {feature.label}
                                </div>
                                <p
                                    className="m-0"
                                    style={{
                                        fontSize: 14,
                                        lineHeight: 1.55,
                                        color: 'var(--pd-steen)',
                                    }}
                                >
                                    {feature.description}
                                </p>
                            </Card>
                        ))}
                    </div>
                </div>
            )}

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="03"
                    title={t('marketing.home.channels.title')}
                    lead={t('marketing.home.channels.lead')}
                />

                <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <Card>
                        <div
                            className="mb-4"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 11,
                                letterSpacing: '0.08em',
                                color: '#8b8a7b',
                                textTransform: 'uppercase',
                            }}
                        >
                            {t('marketing.home.channels.layout')}
                        </div>
                        <DescribedList items={channelSettings.layout} />
                    </Card>

                    <Card>
                        <div
                            className="mb-4"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 11,
                                letterSpacing: '0.08em',
                                color: '#8b8a7b',
                                textTransform: 'uppercase',
                            }}
                        >
                            {t('marketing.home.channels.posting')}
                        </div>
                        <DescribedList items={channelSettings.posting} />
                    </Card>

                    <Card>
                        <div
                            className="mb-4"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 11,
                                letterSpacing: '0.08em',
                                color: '#8b8a7b',
                                textTransform: 'uppercase',
                            }}
                        >
                            {t('marketing.home.channels.tickets')}
                        </div>
                        <DescribedList items={channelSettings.tickets} />
                    </Card>
                </div>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="04"
                    title={t('marketing.home.workflow.title')}
                    lead={t('marketing.home.workflow.lead', {
                        triggers: workflow.triggers.length,
                        actions: workflow.actions.length,
                    })}
                />

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <Card>
                        <div
                            className="mb-4"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 11,
                                letterSpacing: '0.08em',
                                color: '#8b8a7b',
                                textTransform: 'uppercase',
                            }}
                        >
                            {t('marketing.home.workflow.when')}
                        </div>
                        <DescribedList items={workflow.triggers} />
                    </Card>

                    <Card>
                        <div
                            className="mb-4"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 11,
                                letterSpacing: '0.08em',
                                color: '#8b8a7b',
                                textTransform: 'uppercase',
                            }}
                        >
                            {t('marketing.home.workflow.then')}
                        </div>
                        <DescribedList items={workflow.actions} />
                    </Card>
                </div>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="05"
                    title={t('marketing.home.api.title')}
                    lead={t('marketing.home.api.lead')}
                />

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <Card>
                        <div
                            className="mb-4"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 11,
                                letterSpacing: '0.08em',
                                color: '#8b8a7b',
                                textTransform: 'uppercase',
                            }}
                        >
                            {t('marketing.home.api.endpoints')}
                        </div>
                        <ul className="m-0 grid list-none gap-2 p-0">
                            {token.endpoints.map((endpoint) => (
                                <li
                                    key={`${endpoint.method} ${endpoint.path}`}
                                    style={{
                                        fontFamily: 'var(--pd-mono)',
                                        fontSize: 13,
                                    }}
                                >
                                    <span
                                        style={{
                                            color: 'var(--pd-steen)',
                                            display: 'inline-block',
                                            minWidth: '4.5rem',
                                        }}
                                    >
                                        {endpoint.method}
                                    </span>
                                    {endpoint.path}
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card>
                        <div
                            className="mb-4"
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 11,
                                letterSpacing: '0.08em',
                                color: '#8b8a7b',
                                textTransform: 'uppercase',
                            }}
                        >
                            {t('marketing.home.api.tools')}
                        </div>
                        <DescribedList
                            items={token.tools.map((tool) => ({
                                label: tool.name,
                                description: tool.description,
                            }))}
                        />
                    </Card>
                </div>

                <Note>{t('marketing.home.api.note')}</Note>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="06"
                    title={t('marketing.home.roles.title')}
                    lead={t('marketing.home.roles.lead')}
                />

                {/*
                    Rights are the rows and roles are the columns, which is the
                    way round the application grew into. There are eleven rights
                    and four roles, so the other orientation would be a table
                    twelve columns wide — and the list of rights is the more
                    useful half anyway: it is what somebody composes a role of
                    their own out of.

                    The table keeps its own scroll all the same. Four columns of
                    role names do not fold into a phone, and a table that wraps
                    mid-word is less readable than one you push sideways. What
                    matters is that the box scrolls and the page does not.
                */}
                <div className="-mx-6 overflow-x-auto px-6 sm:mx-0 sm:px-0">
                    <table
                        className="w-full min-w-[34rem] border-collapse"
                        style={{ fontSize: 14 }}
                    >
                        <thead>
                            <tr style={bodyRow}>
                                <th
                                    className="py-3 text-left"
                                    style={headCell}
                                    scope="col"
                                >
                                    {t('marketing.home.roles.ability')}
                                </th>
                                {roles.map((role) => (
                                    <th
                                        key={role.value}
                                        className="py-3 text-left"
                                        style={headCell}
                                        scope="col"
                                    >
                                        {role.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {/*
                                Browsing first, and on its own. It is not one of
                                the abilities — it is a column on the role,
                                because it decides what exists for somebody
                                rather than what they may do with it — so it
                                cannot come out of the loop below, and putting
                                it at the top is what makes the guest column
                                read the way it actually works.
                            */}
                            <tr style={bodyRow}>
                                <th
                                    className="py-3 text-left"
                                    style={rowHead}
                                    scope="row"
                                >
                                    {t('marketing.home.roles.browse')}
                                </th>
                                {roles.map((role) => (
                                    <td className="py-3" key={role.value}>
                                        <Mark on={role.canBrowseWorkspace} />
                                    </td>
                                ))}
                            </tr>

                            {abilities.map((ability) => (
                                <tr key={ability.value} style={bodyRow}>
                                    <th
                                        className="py-3 text-left"
                                        style={rowHead}
                                        scope="row"
                                    >
                                        {ability.label}
                                    </th>
                                    {roles.map((role) => (
                                        <td className="py-3" key={role.value}>
                                            <Mark
                                                on={role.abilities.includes(
                                                    ability.value,
                                                )}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p
                    className="mt-4 max-w-[68ch]"
                    style={{
                        fontSize: 13,
                        lineHeight: 1.6,
                        color: '#8b8a7b',
                    }}
                >
                    {t('marketing.home.roles.browse_note')}
                </p>

                <Note>
                    {t('marketing.home.roles.note')}
                    <span className="mt-3 block">
                        {t('marketing.home.roles.ceiling')}
                    </span>
                </Note>
            </div>
        </>
    );
}
