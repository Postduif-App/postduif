import { Head, Link } from '@inertiajs/react';

import { DoveMark } from '@/components/marketing/logo';
import { register } from '@/routes';

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
    canManageWorkspace: boolean;
    canInviteMembers: boolean;
    canBrowseWorkspace: boolean;
    canCreateChannels: boolean;
    canSendTransfers: boolean;
}

/** A label with the sentence the settings screen already puts under it. */
interface Described {
    label: string;
    description: string;
}

interface HomeProps {
    features: Feature[];
    roles: Role[];
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

/** A numbered section head, as the huisstijl sets them out. */
function SectionHead({
    number,
    title,
    lead,
}: {
    number: string;
    title: string;
    lead: string;
}) {
    return (
        <div className="mb-10 flex flex-wrap items-baseline gap-4">
            <span
                style={{
                    fontFamily: 'var(--pd-mono)',
                    fontSize: 12,
                    color: '#8b8a7b',
                    letterSpacing: '0.08em',
                }}
            >
                {number}
            </span>
            <h2 style={{ fontSize: 32, letterSpacing: '-0.03em', margin: 0 }}>
                {title}
            </h2>
            <p
                className="m-0 max-w-[46ch]"
                style={{
                    fontSize: 15,
                    color: 'var(--pd-steen)',
                    lineHeight: 1.5,
                }}
            >
                {lead}
            </p>
        </div>
    );
}

function Card({
    children,
    className = '',
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`p-6 ${className}`}
            style={{
                background: 'var(--pd-wit)',
                border: '1px solid var(--pd-zand)',
                borderRadius: 10,
            }}
        >
            {children}
        </div>
    );
}

function Mark({ on }: { on: boolean }) {
    return (
        <span
            aria-label={on ? 'ja' : 'nee'}
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
/** A short list of label-and-sentence pairs, as the huisstijl sets them out. */
function Described({ items }: { items: Described[] }) {
    return (
        <ul className="m-0 grid list-none gap-3 p-0">
            {items.map((item) => (
                <li key={item.label}>
                    <span
                        style={{
                            fontFamily: 'var(--pd-mono)',
                            fontSize: 14,
                            fontWeight: 600,
                        }}
                    >
                        {item.label}
                    </span>
                    <p
                        className="m-0 mt-1"
                        style={{
                            fontSize: 14,
                            lineHeight: 1.55,
                            color: 'var(--pd-steen)',
                        }}
                    >
                        {item.description}
                    </p>
                </li>
            ))}
        </ul>
    );
}

export default function MarketingHome({
    features,
    roles,
    channelSettings,
    workflow,
    token,
}: HomeProps) {
    const optIn = features.filter((feature) => !feature.onByDefault);

    return (
        <>
            <Head title="Postduif" />

            {/* Hero, on ink, with the 48px grid the huisstijl uses. */}
            <section
                className="relative overflow-hidden px-6 pt-24 sm:px-12"
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

                <div className="relative mx-auto max-w-[1120px]">
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
                        Gasten van buiten, zonder ze de rest te laten zien
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
                        Het gesprek en het werk op één plek.
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
                        Kanalen en threads, tickets voor wat er blijft liggen,
                        en bestanden die te groot zijn om mee te sturen. Klanten
                        doen mee als gast en zien alleen hun eigen kanalen.
                    </p>

                    <div className="mb-16 flex flex-wrap items-center gap-3.5">
                        <Link href={register()} className="pd-button">
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
                                Beginnen →
                            </span>
                        </Link>
                        <span
                            style={{
                                fontFamily: 'var(--pd-mono)',
                                fontSize: 13,
                                color: 'var(--pd-steen)',
                            }}
                        >
                            {features.length} onderdelen · {optIn.length}{' '}
                            standaard uit
                        </span>
                    </div>

                    <div className="h-24" />
                </div>
            </section>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="01"
                    title="Wat er in zit"
                    lead="Elk onderdeel hieronder staat als klasse in de code, met deze naam en deze omschrijving. Wat er niet in staat, staat er niet."
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
                                            STANDAARD UIT
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
                        title="Jij zet het aan"
                        lead="Deze onderdelen staan uit tot iemand ze aanzet. Het zijn precies de onderdelen die iets buiten je workspace laten reiken."
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
                    title="Een kanaal naar de vorm van het gesprek"
                    lead="Een kanaal is niets dat je aanzet, dus het staat niet in de lijst hierboven — terwijl het wel is waar je de hele dag in zit. Dit zijn de knoppen eronder."
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
                            Weergave
                        </div>
                        <Described items={channelSettings.layout} />
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
                            Wie er post
                        </div>
                        <Described items={channelSettings.posting} />
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
                            Tickets
                        </div>
                        <Described items={channelSettings.tickets} />
                    </Card>
                </div>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="04"
                    title="Dingen die je workspace zelf doet"
                    lead={`Een workflow is één aanleiding en daarna een reeks stappen, met voorwaarden en splitsingen ertussen. ${workflow.triggers.length} aanleidingen en ${workflow.actions.length} stappen om uit te kiezen.`}
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
                            Wanneer
                        </div>
                        <Described items={workflow.triggers} />
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
                            Wat er dan gebeurt
                        </div>
                        <Described items={workflow.actions} />
                    </Card>
                </div>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="05"
                    title="Voor je eigen script en je AI-client"
                    lead="Twee deuren, elk met hun eigen sleutel: een persoonlijk token voor je eigen script, OAuth voor een AI-client die zichzelf aanmeldt. Wat erachter zit is precies wat jij mag zien — elke aanroep loopt langs dezelfde regels als het scherm."
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
                            De API
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
                            Wat een AI-client kan, na jouw toestemming
                        </div>
                        <Described
                            items={token.tools.map((tool) => ({
                                label: tool.name,
                                description: tool.description,
                            }))}
                        />
                    </Card>
                </div>

                <p
                    className="mt-6 max-w-[68ch]"
                    style={{
                        fontSize: 15,
                        lineHeight: 1.6,
                        color: 'var(--pd-steen)',
                        borderLeft: '2px solid var(--pd-geel)',
                        paddingLeft: 16,
                    }}
                >
                    Een AI-client meldt zich met OAuth aan en vraagt jou om
                    toestemming; je ziet op een scherm van Postduif wat hij mag
                    en trekt het met één klik weer in. En het staat per
                    workspace standaard uit: zolang die schakelaar uit is, komt
                    er langs deze kant niets naar binnen of naar buiten.
                </p>
            </div>

            <div className="mx-auto max-w-[1120px] px-6 pt-24 sm:px-12">
                <SectionHead
                    number="06"
                    title="Wie wat mag"
                    lead="Een gast is iemand van buiten: een klant, een leverancier. Ze zien alleen de kanalen waarvoor ze zijn uitgenodigd."
                />

                <div className="overflow-x-auto">
                    <table
                        className="w-full min-w-[34rem] border-collapse"
                        style={{ fontSize: 14 }}
                    >
                        <thead>
                            <tr
                                style={{
                                    borderBottom: '1px solid var(--pd-zand)',
                                }}
                            >
                                {[
                                    'Rol',
                                    'Beheren',
                                    'Uitnodigen',
                                    'Workspace zien',
                                    'Kanalen maken',
                                    'Bestanden versturen',
                                ].map((head) => (
                                    <th
                                        key={head}
                                        className="py-3 text-left"
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 11,
                                            letterSpacing: '0.08em',
                                            color: '#8b8a7b',
                                            fontWeight: 400,
                                            textTransform: 'uppercase',
                                        }}
                                    >
                                        {head}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {roles.map((role) => (
                                <tr
                                    key={role.value}
                                    style={{
                                        borderBottom:
                                            '1px solid var(--pd-zand)',
                                    }}
                                >
                                    <td
                                        className="py-3"
                                        style={{
                                            fontFamily: 'var(--pd-mono)',
                                            fontSize: 13,
                                            fontWeight: 600,
                                        }}
                                    >
                                        {role.label}
                                    </td>
                                    <td className="py-3">
                                        <Mark on={role.canManageWorkspace} />
                                    </td>
                                    <td className="py-3">
                                        <Mark on={role.canInviteMembers} />
                                    </td>
                                    <td className="py-3">
                                        <Mark on={role.canBrowseWorkspace} />
                                    </td>
                                    <td className="py-3">
                                        <Mark on={role.canCreateChannels} />
                                    </td>
                                    <td className="py-3">
                                        <Mark on={role.canSendTransfers} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p
                    className="mt-6 max-w-[68ch]"
                    style={{
                        fontSize: 15,
                        lineHeight: 1.6,
                        color: 'var(--pd-steen)',
                        borderLeft: '2px solid var(--pd-geel)',
                        paddingLeft: 16,
                    }}
                >
                    Deze tabel komt uit dezelfde code die de rechten afdwingt.
                    Verandert de regel, dan verandert de tabel.
                </p>
            </div>
        </>
    );
}
