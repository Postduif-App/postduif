export interface EmojiEntry {
    emoji: string;
    /** Search terms, Dutch first — people type in the language they chat in. */
    keywords: string[];
}

export interface EmojiGroup {
    label: string;
    entries: EmojiEntry[];
}

/**
 * A curated set rather than the whole Unicode emoji table.
 *
 * The full table with its keyword index is a few hundred kilobytes and would
 * mean pulling in a dataset package; this covers what a work chat reaches for.
 * The server accepts any emoji, so growing this list is the only change adding
 * one takes.
 */
export const EMOJI_GROUPS: EmojiGroup[] = [
    {
        label: 'Reacties',
        entries: [
            {
                emoji: '👍',
                keywords: ['duim', 'goed', 'ja', 'oke', 'thumbsup'],
            },
            { emoji: '👎', keywords: ['duim omlaag', 'nee', 'slecht'] },
            { emoji: '👏', keywords: ['applaus', 'klappen', 'bravo'] },
            { emoji: '🙌', keywords: ['handen', 'hoera', 'gelukt'] },
            { emoji: '🙏', keywords: ['dank', 'bedankt', 'alsjeblieft'] },
            { emoji: '🤝', keywords: ['deal', 'akkoord', 'handdruk'] },
            { emoji: '💪', keywords: ['sterk', 'kracht', 'zetten'] },
            { emoji: '🫡', keywords: ['salueren', 'komt goed', 'begrepen'] },
            { emoji: '👀', keywords: ['ogen', 'kijken', 'let op'] },
            { emoji: '✅', keywords: ['klaar', 'af', 'vinkje', 'gedaan'] },
            { emoji: '❌', keywords: ['fout', 'nee', 'kruis'] },
            { emoji: '⚠️', keywords: ['let op', 'waarschuwing'] },
        ],
    },
    {
        label: 'Blij',
        entries: [
            { emoji: '😀', keywords: ['lach', 'blij'] },
            { emoji: '😂', keywords: ['huilen van het lachen', 'grappig'] },
            { emoji: '🤣', keywords: ['rollen', 'lachen', 'grappig'] },
            { emoji: '😅', keywords: ['zweet', 'oeps', 'net goed'] },
            { emoji: '😊', keywords: ['blij', 'blozen', 'lief'] },
            { emoji: '😍', keywords: ['verliefd', 'mooi', 'hartjes'] },
            { emoji: '🥳', keywords: ['feest', 'hoera', 'party'] },
            { emoji: '😎', keywords: ['cool', 'zonnebril'] },
            { emoji: '🤩', keywords: ['sterren', 'wauw', 'geweldig'] },
            { emoji: '😉', keywords: ['knipoog'] },
            { emoji: '🙂', keywords: ['glimlach'] },
            { emoji: '😇', keywords: ['engel', 'onschuldig'] },
        ],
    },
    {
        label: 'Twijfel',
        entries: [
            { emoji: '🤔', keywords: ['denken', 'hmm', 'twijfel'] },
            { emoji: '🤨', keywords: ['wenkbrauw', 'serieus'] },
            { emoji: '😬', keywords: ['ai', 'ongemakkelijk'] },
            { emoji: '😐', keywords: ['neutraal', 'geen mening'] },
            { emoji: '🤷', keywords: ['geen idee', 'schouders'] },
            { emoji: '😴', keywords: ['slaap', 'saai'] },
            { emoji: '🥲', keywords: ['traan', 'toch blij'] },
            { emoji: '😢', keywords: ['huilen', 'jammer', 'triest'] },
            { emoji: '😭', keywords: ['huilen', 'erg'] },
            { emoji: '😱', keywords: ['schrik', 'oh nee'] },
            { emoji: '🤯', keywords: ['mindblown', 'wauw', 'ontploffen'] },
            { emoji: '😳', keywords: ['ongemakkelijk', 'oeps'] },
        ],
    },
    {
        label: 'Werk',
        entries: [
            { emoji: '🚀', keywords: ['live', 'deploy', 'raket', 'snel'] },
            { emoji: '🔥', keywords: ['vuur', 'goed', 'brand', 'urgent'] },
            { emoji: '🎉', keywords: ['feest', 'gelukt', 'hoera'] },
            { emoji: '🐛', keywords: ['bug', 'fout', 'beestje'] },
            { emoji: '🛠️', keywords: ['fix', 'gereedschap', 'bouwen'] },
            { emoji: '📌', keywords: ['pin', 'belangrijk', 'onthouden'] },
            { emoji: '📝', keywords: ['notitie', 'schrijven', 'aantekening'] },
            { emoji: '📅', keywords: ['agenda', 'datum', 'planning'] },
            { emoji: '⏰', keywords: ['tijd', 'deadline', 'wekker'] },
            { emoji: '💡', keywords: ['idee', 'lamp', 'inzicht'] },
            { emoji: '🧠', keywords: ['brein', 'slim', 'nadenken'] },
            { emoji: '📈', keywords: ['groei', 'omhoog', 'cijfers'] },
            { emoji: '📉', keywords: ['daling', 'omlaag', 'cijfers'] },
            { emoji: '🧹', keywords: ['opruimen', 'schoonmaken', 'cleanup'] },
            { emoji: '🚧', keywords: ['werk in uitvoering', 'wip', 'bezig'] },
            { emoji: '🔒', keywords: ['veilig', 'slot', 'privé'] },
            { emoji: '💸', keywords: ['geld', 'kosten', 'betalen'] },
            { emoji: '☕', keywords: ['koffie', 'pauze'] },
        ],
    },
    {
        label: 'Overig',
        entries: [
            { emoji: '❤️', keywords: ['hart', 'liefde', 'mooi'] },
            { emoji: '🧡', keywords: ['hart', 'oranje'] },
            { emoji: '💜', keywords: ['hart', 'paars'] },
            { emoji: '💯', keywords: ['honderd', 'helemaal', 'top'] },
            { emoji: '⭐', keywords: ['ster', 'favoriet'] },
            { emoji: '✨', keywords: ['sprankelend', 'nieuw', 'mooi'] },
            { emoji: '🎯', keywords: ['doel', 'precies', 'raak'] },
            { emoji: '🏆', keywords: ['winnaar', 'beker', 'prijs'] },
            { emoji: '🍰', keywords: ['cake', 'gebak', 'traktatie'] },
            { emoji: '🍕', keywords: ['pizza', 'eten'] },
            { emoji: '🎂', keywords: ['verjaardag', 'taart'] },
            { emoji: '🌞', keywords: ['zon', 'mooi weer'] },
            { emoji: '🌧️', keywords: ['regen', 'weer'] },
            { emoji: '🐳', keywords: ['walvis', 'docker'] },
            { emoji: '🦄', keywords: ['eenhoorn', 'bijzonder'] },
            { emoji: '🌱', keywords: ['groei', 'plant', 'begin'] },
            { emoji: '🎈', keywords: ['ballon', 'feest'] },
            { emoji: '🧊', keywords: ['ijs', 'koud', 'bevroren'] },
        ],
    },
];

/** The quick row's fallback, for a browser with no history yet. */
export const QUICK_EMOJI = ['👍', '🎉', '❤️', '😂', '👀', '🙏', '🚀', '✅'];
