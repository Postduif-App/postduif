/**
 * The mark: a dove in profile, looking right.
 *
 * Two paths and one colour, straight out of the huisstijl — the wing and the
 * body are separate shapes but never separate fills, because the rule is one
 * flat colour with no detail that disappears at 16px.
 *
 * currentColor rather than a fixed fill, so the same file serves yellow on ink
 * and ink on yellow without a second copy.
 */
export function DoveMark({ size = 24 }: { size?: number }) {
    return (
        <svg viewBox="0 0 48 48" width={size} height={size} aria-hidden="true">
            <g fill="currentColor">
                <path d="M21 23.6L1.5 21.4L19.5 32Z" />
                <path d="M30.5 7.6C35.6 6.6 39.6 10 39.6 14L45.6 14.6L39 18.2C42 22 42.2 30 35.8 33.4C30.2 36.4 22.4 35.4 18.8 31.6C15.6 28.2 18.6 21.4 25.6 18.6C26 13.2 27 9 30.5 7.6Z" />
            </g>
        </svg>
    );
}

/**
 * Mark and wordmark together.
 *
 * The wordmark is always lowercase — a rule in the huisstijl rather than a
 * stylistic accident, so it is written that way here instead of being left to a
 * CSS transform somebody could override.
 */
export function Wordmark({
    size = 'sm',
    on = 'paper',
}: {
    size?: 'sm' | 'lg';
    /**
     * Which surface it sits on. On paper the mark is a dark tile with a yellow
     * dove; on ink the tile disappears and the dove carries the yellow itself.
     * Two arrangements rather than one, because the huisstijl allows yellow on
     * ink and ink on yellow — but never yellow on white.
     */
    on?: 'paper' | 'ink';
}) {
    const large = size === 'lg';
    const onInk = on === 'ink';

    return (
        <span className="flex items-center gap-[11px]">
            <span
                className="flex items-center justify-center rounded-[6px]"
                style={{
                    width: large ? 52 : 30,
                    height: large ? 52 : 30,
                    color: 'var(--pd-geel)',
                    ...(onInk ? {} : { background: 'var(--pd-inkt)' }),
                }}
            >
                <DoveMark size={large ? 36 : 20} />
            </span>
            <span
                style={{
                    fontFamily: 'var(--pd-mono)',
                    fontWeight: 600,
                    fontSize: large ? 28 : 16,
                    letterSpacing: '-0.045em',
                    color: onInk ? 'var(--pd-papier)' : 'var(--pd-inkt)',
                }}
            >
                postduif
            </span>
        </span>
    );
}
