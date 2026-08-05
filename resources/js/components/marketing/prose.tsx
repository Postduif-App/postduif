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

/** A numbered section head, as the huisstijl sets them out. */
export function SectionHead({
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
 * An aside in the huisstijl's yellow rule.
 *
 * Used for the thing a reader has to know but did not ask — the workspace
 * switch that is off by default, the reason a token cannot reach everything.
 */
export function Note({ children }: { children: ReactNode }) {
    return (
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
            {children}
        </p>
    );
}
