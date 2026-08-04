import { useEffect, useRef } from 'react';

import { fireConfetti } from '@/lib/confetti';

/**
 * De hele feestemoji moet in beeld staan, niet de bovenste pixel ervan: het
 * feest hoort bij het lezen van het bericht, niet bij het langsscrollen.
 */
const THRESHOLD = 0.9;

/**
 * Laat confetti los zodra het element in beeld komt, één keer.
 *
 * De ref hangt aan de rij zelf en niet aan de lijst, want een kanaal kan meer
 * dan één 🎉 bevatten en alleen degene die je nu leest hoort te vieren. Het
 * afsluiten van de observer na de eerste keer is bewust: een rij blijft
 * gemonteerd terwijl je scrollt, dus zonder dat zou hetzelfde bericht bij elke
 * terugkeer opnieuw losbarsten.
 *
 * Geeft `enabled` false, dan wordt er niets waargenomen — een verwijderd
 * bericht toont een grafsteen en heeft niets te vieren.
 */
export function useConfettiOnView<T extends HTMLElement>(enabled: boolean) {
    const ref = useRef<T>(null);

    useEffect(() => {
        const element = ref.current;

        if (!enabled || !element) {
            return;
        }

        // Ontbreekt de waarnemer, dan blijft het bericht gewoon een grote
        // emoji. Confetti is versiering; het is geen reden om iets te breken.
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (!entries.some((entry) => entry.isIntersecting)) {
                    return;
                }

                observer.disconnect();
                fireConfetti();
            },
            { threshold: THRESHOLD },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, [enabled]);

    return ref;
}
