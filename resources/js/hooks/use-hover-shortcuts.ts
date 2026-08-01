import { useEffect, useRef } from 'react';

/** Losse letters, gekoppeld aan wat ze doen. Een ontbrekende waarde betekent
 * dat die toets hier niets mag: verwijderen kan alleen bij eigen berichten. */
export type HoverShortcuts = Record<string, (() => void) | undefined>;

/**
 * De letter waar deze toetsaanslag om vraagt, of null als het er geen is.
 *
 * Modifier-combinaties horen bij de browser en het besturingssysteem — Cmd+R
 * herlaadt de pagina en dat moet zo blijven. Shift valt er ook buiten: dat is
 * de aanloop naar hoofdletters en naar selecteren, niet naar een actie. Alles
 * wat langer is dan één teken ('Enter', 'ArrowUp') is een navigatietoets.
 */
export function hoverShortcutKey(event: {
    key: string;
    metaKey: boolean;
    ctrlKey: boolean;
    altKey: boolean;
    shiftKey: boolean;
}): string | null {
    if (event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) {
        return null;
    }

    if (event.key.length !== 1) {
        return null;
    }

    return event.key.toLowerCase();
}

/**
 * Typt iemand ergens? Dan is een losse letter tekst, geen opdracht.
 */
function typing(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    );
}

/**
 * Ligt er een laag over de pagina?
 *
 * Een modale dialog dekt de berichten af, dus daar loop je met de muis niet
 * meer overheen — maar de emoji-lijst opent binnen het bericht zelf, en dan
 * blijft de rij "gehoverd" terwijl je aandacht ergens anders is. Zonder deze
 * controle zou d tijdens het kiezen van een reactie het bericht wissen.
 */
function overlayOpen(): boolean {
    return (
        document.querySelector(
            '[role="dialog"], [role="menu"], [role="listbox"]',
        ) !== null
    );
}

/**
 * Voer een actie uit op de toets die erbij hoort, zolang `active` waar is.
 *
 * Bedoeld voor acties die aan de muis hangen: het element waar je overheen
 * staat heeft geen focus, dus er is geen element om op te luisteren en het
 * moet via het document. Alleen de gehoverde rij zet `active` op waar, dus er
 * hangt er hooguit één tegelijk aan.
 */
export function useHoverShortcuts(
    active: boolean,
    shortcuts: HoverShortcuts,
): void {
    // Een verse objectliteral per render zou de listener elke render opnieuw
    // op- en afhangen; via een ref blijft alleen `active` een reden om dat te
    // doen, terwijl de acties wel actueel blijven.
    const latest = useRef(shortcuts);

    useEffect(() => {
        latest.current = shortcuts;
    });

    useEffect(() => {
        if (!active) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.defaultPrevented) {
                return;
            }

            const key = hoverShortcutKey(event);

            if (key === null) {
                return;
            }

            const shortcut = latest.current[key];

            if (!shortcut) {
                return;
            }

            if (typing(event.target) || overlayOpen()) {
                return;
            }

            event.preventDefault();
            shortcut();
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [active]);
}
