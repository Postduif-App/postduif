import { Head, Link, usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';

import { DoveMark } from '@/components/marketing/logo';
import { ChannelSketch } from '@/components/marketing/preview';
import {
    Band,
    Card,
    CardLabel,
    ChipCloud,
    Note,
    SectionHead,
    SpecList,
    Tally,
} from '@/components/marketing/prose';
import type { Described } from '@/components/marketing/prose';
import { useTranslate } from '@/hooks/use-translate';
import { SOURCE_URL } from '@/lib/postduif';
import { docs, login, register } from '@/routes';

interface Feature {
    key: string;
    label: string;
    description: string;
    /** False for the ones that only exist once somebody switches them on. */
    onByDefault: boolean;
    /** Which heading it falls under; see FeatureGroup on the server. */
    group: string;
}

/** A heading the list falls apart under, in the order it should be read. */
interface Group {
    value: string;
    label: string;
    description: string;
}

/**
 * One of the three the page leads with.
 *
 * Its name and description are the feature's own, like every other card here.
 * The pitch is the exception, and the only sentence on this page written rather
 * than derived — see BuildFeatureInventory::spotlight() for why that is allowed
 * to be true of exactly these three and nothing else.
 */
interface Spotlit {
    key: string;
    label: string;
    description: string;
    pitch: string;
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
    featureGroups: Group[];
    spotlight: Spotlit[];
    roles: Role[];
    abilities: Ability[];
    channelSettings: {
        layout: Described[];
        posting: Described[];
        tickets: Described[];
        documents: Described[];
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
 * Pulled out of the JSX because the table builds its rows and its columns from
 * two arrays rather than writing them out, and a style object repeated inside
 * two nested maps is the kind of thing that drifts apart one cell at a time.
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
 *
 * Waar het op gebouwd is: papier, wit en inkt om en om, zoals app.css bij de
 * merkkleuren al aankondigt. En één regel over hoeveel er van iets te zeggen
 * valt — wat iets *is* krijgt een zin, wat je uit een lijst *kiest* krijgt
 * alleen zijn naam. Anders staan er alleen al onder "workflows" vijfenzeventig
 * alinea's, en dan leest niemand de drie zinnen die er echt toe doen.
 */
export default function MarketingHome({
    features,
    featureGroups,
    spotlight,
    roles,
    abilities,
    channelSettings,
    workflow,
    token,
}: HomeProps) {
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
                className="relative overflow-hidden pt-20 sm:pt-24"
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
                <div className="relative mx-auto max-w-[1120px] px-6 pb-20 sm:px-12 sm:pb-24">
                    {/*
                        Twee kolommen vanaf lg: de belofte links, het product
                        rechts. Daaronder onder elkaar, met de schets als
                        laatste — op een telefoon is de knop het enige dat er in
                        het eerste scherm toe doet.
                    */}
                    <div className="grid items-center gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,420px)] lg:gap-16">
                        <div>
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
                                {t('marketing.home.eyebrow')}
                            </div>

                            <h1
                                className="m-0 mb-6 max-w-[14ch] text-[44px] sm:text-[64px]"
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
                                className="m-0 mb-9 max-w-[48ch]"
                                style={{
                                    fontSize: 18,
                                    lineHeight: 1.6,
                                    color: '#b6b4a5',
                                    textWrap: 'pretty',
                                }}
                            >
                                {t('marketing.home.intro')}
                            </p>

                            <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                                {/*
                                    Where this points depends on whether the
                                    door is open. An installation that closed
                                    registration answers /register with a 404,
                                    and the button that says "Beginnen" is
                                    exactly the one somebody presses first — so
                                    it becomes the way in that does work, rather
                                    than being taken away and leaving a landing
                                    page with nothing to press. The button in
                                    the navbar follows the same rule; see the
                                    marketing layout.
                                */}
                                <Link
                                    href={
                                        registrationOpen ? register() : login()
                                    }
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

                                {/*
                                    Een gewone <a> en geen Inertia-Link: dit
                                    gaat de site uit, en Link zou GitHub als
                                    Inertia-antwoord proberen op te halen. In
                                    geel, want dat is de enige kleur op de inkt
                                    die naast de knop nog leesbaar is zonder een
                                    tweede knop te lijken.
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
                        </div>

                        <ChannelSketch />
                    </div>

                    {/*
                        De tellingen onder de vouw, op een lijn. Ze stonden
                        eerder als bijzin naast de knop; als getal zijn ze wat
                        ze zijn — een omvang — en ze zeggen daar meer dan als
                        halve zin in het grijs.
                    */}
                    <div
                        className="mt-16 grid grid-cols-2 gap-8 pt-10 sm:grid-cols-4"
                        style={{ borderTop: '1px solid #3a3930' }}
                    >
                        <Tally
                            value={features.length}
                            unit={t('marketing.home.tally.features')}
                            on="inkt"
                        />
                        <Tally
                            value={roles.length}
                            unit={t('marketing.home.tally.roles')}
                            on="inkt"
                        />
                        <Tally
                            value={workflow.triggers.length}
                            unit={t('marketing.home.tally.triggers')}
                            on="inkt"
                        />
                        <Tally
                            value={workflow.actions.length}
                            unit={t('marketing.home.tally.actions')}
                            on="inkt"
                        />
                    </div>
                </div>
            </section>

            {/*
                De drie waar iemand voor komt, in inkt op het papier.

                Bewust dezelfde kleur als de hero en niet nog een witte kaart:
                achttien gelijke regels verderop zeggen dat alles even zwaar
                weegt, en dat is precies wat een bezoeker die niets van dit
                product weet niet kan gebruiken. Dit is de enige plek op de
                pagina waar een oordeel staat — welke drie, en waarom — en die
                komt uit lang/, niet uit de applicatie.
            */}
            <Band tone="papier">
                <SectionHead
                    title={t('marketing.home.spotlight.title')}
                    lead={t('marketing.home.spotlight.lead')}
                />

                <ul className="grid list-none grid-cols-1 gap-6 p-0 md:grid-cols-3">
                    {spotlight.map((item, index) => (
                        <li key={item.key} className="h-full">
                            <div
                                className="flex h-full flex-col p-7"
                                style={{
                                    background: 'var(--pd-inkt)',
                                    color: 'var(--pd-papier)',
                                    borderRadius: 10,
                                }}
                            >
                                <div className="mb-5 flex items-center gap-2.5">
                                    <span style={{ color: 'var(--pd-geel)' }}>
                                        <DoveMark size={18} />
                                    </span>
                                    <span
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 17,
                                            fontWeight: 600,
                                        }}
                                    >
                                        {item.label}
                                    </span>
                                    <span
                                        className="ml-auto"
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 12,
                                            color: '#6f6e61',
                                        }}
                                    >
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                </div>

                                <p
                                    className="m-0"
                                    style={{
                                        fontSize: 15,
                                        lineHeight: 1.65,
                                        color: '#d8d6c8',
                                        textWrap: 'pretty',
                                    }}
                                >
                                    {item.pitch}
                                </p>

                                {/*
                                    De zin uit de featureklasse eronder, achter
                                    een lijn. Klein en apart, want dit is de zin
                                    die een beheerder in zijn eigen
                                    instellingen leest: hij hoort hier als
                                    bewijs, niet als belofte.
                                */}
                                <p
                                    className="m-0 mt-auto pt-6"
                                    style={{
                                        fontFamily: 'var(--pd-mono)',
                                        fontSize: 12,
                                        lineHeight: 1.55,
                                        color: '#8b8a7b',
                                        borderTop: '1px solid #3a3930',
                                        marginTop: 24,
                                    }}
                                >
                                    {item.description}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            </Band>

            <Band tone="wit">
                <SectionHead
                    title={t('marketing.home.features.title')}
                    lead={t('marketing.home.features.lead')}
                />

                {/*
                    Per groep, en als register in plaats van als kaarten. De
                    indeling komt van de featureklassen zelf — group() is
                    abstract, dus een nieuw onderdeel valt altijd ergens onder —
                    en de volgorde binnen een groep blijft die van
                    WorkspaceFeature::ALL, want dat is een keuze van de
                    applicatie en niet van deze pagina.

                    Een groep zonder onderdelen valt weg. De server geeft elke
                    case mee, ook een lege, juist zodat die keuze hier ligt.
                */}
                {featureGroups.map((group) => {
                    const items = features.filter(
                        (feature) => feature.group === group.value,
                    );

                    if (items.length === 0) {
                        return null;
                    }

                    return (
                        <section key={group.value} className="mb-12 last:mb-0">
                            {/*
                                Naam en aantal op één regel, de zin eronder.

                                Dezelfde afweging als bij SectionHead: een zin
                                die naast een kop begint, krijgt de ruimte die
                                die kop toevallig overlaat — "Het gesprek" laat
                                veel over en "Het werk dat eruit volgt" bijna
                                niets, dus dezelfde component las per groep
                                anders. Het aantal blijft er wél naast staan:
                                dat zijn twee woorden en het hoort bij de kop.
                            */}
                            <div className="mb-4">
                                <div className="flex flex-wrap items-baseline gap-x-3">
                                    <h3
                                        className="m-0"
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 17,
                                            fontWeight: 600,
                                            letterSpacing: '-0.02em',
                                        }}
                                    >
                                        {group.label}
                                    </h3>
                                    <span
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 12,
                                            color: '#8b8a7b',
                                        }}
                                    >
                                        {tChoice(
                                            'marketing.home.features.group_count',
                                            items.length,
                                        )}
                                    </span>
                                </div>
                                <p
                                    className="m-0 mt-2 max-w-[58ch]"
                                    style={{
                                        fontSize: 14,
                                        lineHeight: 1.5,
                                        color: 'var(--pd-steen)',
                                    }}
                                >
                                    {group.description}
                                </p>
                            </div>

                            <SpecList items={items} />
                        </section>
                    );
                })}
            </Band>

            <Band tone="papier">
                <SectionHead
                    title={t('marketing.home.channels.title')}
                    lead={t('marketing.home.channels.lead')}
                />

                {/*
                    Alleen de namen van de keuzes, niet de zin bij elke keuze.

                    Wat hier telt is dat je kunt kiezen en waaruit; wat elke
                    stand precies doet, staat in het scherm waar iemand hem
                    aanzet — met dezelfde woorden, want het zijn dezelfde enums.
                */}
                {/*
                    Eén blok met vier regels, niet vier kaarten.

                    Elke kaart hield twee of drie keuzes vast, en een kaart om
                    twee woorden heen is vooral rand: het las als vier lege
                    vakken. Als regels onder elkaar — de vraag links, de
                    antwoorden rechts — staat er hetzelfde in een derde van de
                    hoogte, en lezen de vier vragen als één instellingenblad.
                */}
                <Card className="!p-0">
                    <dl className="m-0">
                        {[
                            {
                                label: t('marketing.home.channels.layout'),
                                items: channelSettings.layout,
                            },
                            {
                                label: t('marketing.home.channels.posting'),
                                items: channelSettings.posting,
                            },
                            {
                                label: t('marketing.home.channels.tickets'),
                                items: channelSettings.tickets,
                            },
                            {
                                label: t('marketing.home.channels.documents'),
                                items: channelSettings.documents,
                            },
                        ].map((row, index) => (
                            <div
                                key={row.label}
                                className="grid items-baseline gap-x-8 gap-y-3 px-6 py-5 sm:grid-cols-[13rem_1fr]"
                                style={
                                    index === 0
                                        ? undefined
                                        : {
                                              borderTop:
                                                  '1px solid var(--pd-zand)',
                                          }
                                }
                            >
                                <dt
                                    style={{
                                        fontFamily: 'var(--pd-mono)',
                                        fontSize: 11,
                                        letterSpacing: '0.08em',
                                        color: '#8b8a7b',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    {row.label}
                                </dt>
                                <dd className="m-0">
                                    <ChipCloud
                                        items={row.items.map(
                                            (item) => item.label,
                                        )}
                                    />
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Card>
            </Band>

            {/*
                Workflows op inkt, want dit is de sectie waar de omvang het
                argument is en een wolk van namen op donker het meest te zien
                geeft. Hier stonden vijfenzeventig zinnen; nu vijfenzeventig
                namen, uit hetzelfde register.
            */}
            <Band tone="inkt">
                <SectionHead
                    title={t('marketing.home.workflow.title')}
                    lead={t('marketing.home.workflow.lead')}
                    on="inkt"
                />

                <div className="grid gap-10 md:grid-cols-2 md:gap-14">
                    <div>
                        <div className="mb-5 flex items-baseline gap-4">
                            <Tally
                                value={workflow.triggers.length}
                                unit={t('marketing.home.workflow.when')}
                                on="inkt"
                            />
                        </div>
                        <ChipCloud
                            items={workflow.triggers.map((item) => item.label)}
                            on="inkt"
                        />
                    </div>

                    <div>
                        <div className="mb-5 flex items-baseline gap-4">
                            <Tally
                                value={workflow.actions.length}
                                unit={t('marketing.home.workflow.then')}
                                on="inkt"
                            />
                        </div>
                        <ChipCloud
                            items={workflow.actions.map((item) => item.label)}
                            on="inkt"
                        />
                    </div>
                </div>
            </Band>

            <Band tone="wit">
                <SectionHead
                    title={t('marketing.home.api.title')}
                    lead={t('marketing.home.api.lead')}
                />

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    {/*
                        De endpoints in een blok dat eruitziet als wat het is.
                        Papier in een witte band, zodat het als code leest en
                        niet als nog een kaart.
                    */}
                    <div
                        className="p-6"
                        style={{
                            background: 'var(--pd-papier)',
                            border: '1px solid var(--pd-zand)',
                            borderRadius: 10,
                        }}
                    >
                        <CardLabel>
                            {t('marketing.home.api.endpoints')}
                        </CardLabel>
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

                        <p className="m-0 mt-5">
                            <Link
                                href={docs()}
                                style={{
                                    fontFamily: 'var(--pd-mono)',
                                    fontSize: 13,
                                }}
                            >
                                {t('marketing.home.api.reference')}
                            </Link>
                        </p>
                    </div>

                    <Card>
                        <CardLabel>{t('marketing.home.api.tools')}</CardLabel>
                        <ChipCloud
                            items={token.tools.map((tool) => tool.name)}
                        />
                        <Note>{t('marketing.home.api.note')}</Note>
                    </Card>
                </div>
            </Band>

            <Band tone="papier">
                <SectionHead
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

                <Note>{t('marketing.home.roles.note')}</Note>
            </Band>
        </>
    );
}
