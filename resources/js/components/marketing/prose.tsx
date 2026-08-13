import type { ReactNode } from 'react';

/**
 * The pieces the public pages are set in, in the huisstijl.
 *
 * Here rather than in a page because there are two pages now — the landing page
 * and the API reference — and a second copy of a section head is how two pages
 * that are meant to look like one site slowly stop doing so.
 */

/** A label with the sentence that belongs under it. */
export interface Described {
    label: string;
    description: string;
}

/**
 * De drie vlakken waar de publieke pagina's op staan.
 *
 * "Dark is de app, licht is het web" laat nog steeds twee tinten licht en één
 * donkere over, en de huisstijl vraagt om afwisseling: een pagina die zeven
 * secties op hetzelfde papier zet, leest als één lange sectie. Wit is het
 * rustpunt tussen twee vellen papier, inkt is de nadruk.
 */
const surfaces = {
    papier: { background: 'var(--pd-papier)', color: 'var(--pd-inkt)' },
    wit: { background: 'var(--pd-wit)', color: 'var(--pd-inkt)' },
    inkt: { background: 'var(--pd-inkt)', color: 'var(--pd-papier)' },
} as const;

export type Surface = keyof typeof surfaces;

/**
 * Eén sectie: het vlak van rand tot rand, de inhoud op de kolombreedte.
 *
 * De achtergrond hoort op het buitenste element en de breedte een niveau
 * daarbinnen. Andersom — padding op de sectie zelf — verspringt het vlak mee
 * met de tekst in plaats van door te lopen tot de rand van het scherm, wat
 * precies de fout is die de hero hierboven ooit maakte.
 */
export function Band({
    tone = 'papier',
    children,
}: {
    tone?: Surface;
    children: ReactNode;
}) {
    return (
        <section style={surfaces[tone]}>
            <div className="mx-auto max-w-[1120px] px-6 py-20 sm:px-12 sm:py-24">
                {children}
            </div>
        </section>
    );
}

/**
 * Eén keuze uit een lijst, als naam zonder zin.
 *
 * Voor de lijsten waar de opsomming zelf het antwoord is: 33 aanleidingen en 42
 * stappen met elk een zin eronder zijn vijfenzeventig alinea's, en niemand
 * leest die — terwijl de namen samen in één oogopslag laten zien hoe ver het
 * reikt. De zin blijft staan waar iemand hem echt nodig heeft: in het scherm
 * waar hij de stap kiest.
 */
export function Chip({
    children,
    on = 'papier',
}: {
    children: ReactNode;
    on?: Surface;
}) {
    return (
        <li
            style={{
                fontFamily: 'var(--pd-mono)',
                fontSize: 12.5,
                lineHeight: 1.2,
                padding: '7px 11px',
                borderRadius: 6,
                whiteSpace: 'nowrap',
                ...(on === 'inkt'
                    ? { border: '1px solid #3a3930', color: '#d8d6c8' }
                    : {
                          border: '1px solid var(--pd-zand)',
                          background: 'var(--pd-wit)',
                          color: 'var(--pd-inkt)',
                      }),
            }}
        >
            {children}
        </li>
    );
}

/** Een wolk van die namen, in de volgorde die het register aanhoudt. */
export function ChipCloud({
    items,
    on = 'papier',
}: {
    items: string[];
    on?: Surface;
}) {
    return (
        <ul className="m-0 flex list-none flex-wrap gap-2 overflow-hidden p-0">
            {items.map((item) => (
                <Chip key={item} on={on}>
                    {item}
                </Chip>
            ))}
        </ul>
    );
}

/**
 * Een getal dat groot genoeg is om zelf het argument te zijn.
 *
 * "42 stappen om uit te kiezen" is een zin die je leest; 42 in Plex Mono op 56px
 * is iets dat je ziet. De eenheid staat eronder in het klein, zoals de
 * huisstijl labels zet.
 */
export function Tally({
    value,
    unit,
    on = 'papier',
}: {
    value: number;
    unit: string;
    on?: Surface;
}) {
    return (
        <div>
            <div
                style={{
                    fontFamily: 'var(--pd-mono)',
                    fontSize: 52,
                    fontWeight: 600,
                    lineHeight: 1,
                    letterSpacing: '-0.05em',
                    color: on === 'inkt' ? 'var(--pd-geel)' : 'var(--pd-inkt)',
                }}
            >
                {value}
            </div>
            <div
                className="mt-2"
                style={{
                    fontFamily: 'var(--pd-mono)',
                    fontSize: 11,
                    letterSpacing: '0.08em',
                    textTransform: 'uppercase',
                    color: on === 'inkt' ? '#8b8a7b' : 'var(--pd-steen)',
                }}
            >
                {unit}
            </div>
        </div>
    );
}

/**
 * De kop boven een sectie: een titel met de zin die eronder hoort.
 *
 * Zonder nummer. Die stonden er als hoofdstuknummers van een handleiding, maar
 * een landingspagina is geen handleiding: niemand slaat sectie 04 op, en het
 * getal beloofde een volgorde die er niet is. De titel is genoeg om te weten
 * waar je bent, en er staat één ding minder in het grijs.
 *
 * De lead onder de titel en niet ernaast: naast de titel hing zijn breedte af
 * van hoe lang die titel toevallig was, dus een korte kop gaf een brede alinea
 * en een lange kop een smalle kolom. Onder elkaar heeft elke sectie dezelfde
 * maat.
 */
export function SectionHead({
    title,
    lead,
    on = 'papier',
}: {
    title: string;
    lead: string;
    on?: Surface;
}) {
    return (
        <div className="mb-10">
            <h2
                style={{
                    fontSize: 34,
                    letterSpacing: '-0.03em',
                    margin: 0,
                    textWrap: 'balance',
                }}
            >
                {title}
            </h2>

            <p
                className="m-0 mt-3 max-w-[56ch]"
                style={{
                    fontSize: 15,
                    // Steen is op inkt te donker; zie de dark-toning in app.css,
                    // die dezelfde afweging voor de app-schermen maakt.
                    color: on === 'inkt' ? '#b6b4a5' : 'var(--pd-steen)',
                    lineHeight: 1.55,
                }}
            >
                {lead}
            </p>
        </div>
    );
}

export function Card({
    children,
    className = '',
}: {
    children: ReactNode;
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

/** The small uppercase mono label that titles a card. */
export function CardLabel({ children }: { children: ReactNode }) {
    return (
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
            {children}
        </div>
    );
}

/** A short list of label-and-sentence pairs, as the huisstijl sets them out. */
export function DescribedList({ items }: { items: Described[] }) {
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

/**
 * Eén regel per onderdeel, met een haarlijn ertussen.
 *
 * De achttien onderdelen stonden in achttien kaarten, en achttien kaarten met
 * een alinea erin lezen als een muur — terwijl dit een register is en geen
 * betoog. Als lijst met lijnen ertussen valt hij in één blik te overzien: de
 * naam links in mono, de zin ernaast, en de regelhoogte doet de rest.
 *
 * Twee kolommen vanaf md, want negen regels naast negen regels is korter dan
 * achttien onder elkaar en de leesvolgorde blijft per groep.
 */
export function SpecList({
    items,
}: {
    items: (Described & { key: string })[];
}) {
    return (
        <ul
            className="m-0 grid list-none grid-cols-1 gap-x-10 p-0 md:grid-cols-2"
            style={{ borderTop: '1px solid var(--pd-zand)' }}
        >
            {items.map((item) => (
                <li
                    key={item.key}
                    className="py-4"
                    style={{ borderBottom: '1px solid var(--pd-zand)' }}
                >
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
                        className="m-0 mt-1.5 max-w-[52ch]"
                        style={{
                            fontSize: 14,
                            lineHeight: 1.5,
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

/**
 * An aside in the huisstijl's yellow rule.
 *
 * Used for the thing a reader has to know but did not ask — the workspace
 * switch that is off by default, the reason a token cannot reach everything.
 */
export function Note({
    children,
    on = 'papier',
}: {
    children: ReactNode;
    on?: Surface;
}) {
    return (
        <p
            className="mt-6 max-w-[68ch]"
            style={{
                fontSize: 15,
                lineHeight: 1.6,
                color: on === 'inkt' ? '#b6b4a5' : 'var(--pd-steen)',
                borderLeft: '2px solid var(--pd-geel)',
                paddingLeft: 16,
            }}
        >
            {children}
        </p>
    );
}
