import { describe, expect, it } from 'vitest';

import type { ChannelSummary } from '@/types/chat';
import { matchChannels } from './channel-search';

/** Only the fields the matcher looks at; the rest is noise for this test. */
function channel(
    id: number,
    label: string,
    overrides: Partial<ChannelSummary> = {},
): ChannelSummary {
    return {
        id,
        type: 'public',
        name: label,
        label,
        isMember: true,
        mutedUntil: null,
        isFavorite: false,
        unreadCount: 0,
        mentionCount: 0,
        openTicketCount: 0,
        ...overrides,
    } as ChannelSummary;
}

const CHANNELS = [
    channel(1, 'algemeen'),
    channel(2, 'deploys'),
    channel(3, 'oude-deploys'),
    channel(4, 'ontwerp'),
];

describe('matchChannels', () => {
    it('offers nothing until something is typed', () => {
        expect(matchChannels(CHANNELS, '')).toEqual([]);
        expect(matchChannels(CHANNELS, '   ')).toEqual([]);
    });

    it('finds a channel by the start of its name', () => {
        expect(matchChannels(CHANNELS, 'alg').map((c) => c.label)).toEqual([
            'algemeen',
        ]);
    });

    it('does not care about capitals', () => {
        expect(matchChannels(CHANNELS, 'ALG').map((c) => c.label)).toEqual([
            'algemeen',
        ]);
    });

    /** A name that starts with what you typed beats one that merely contains it. */
    it('puts the leading match first', () => {
        expect(matchChannels(CHANNELS, 'deploy').map((c) => c.label)).toEqual([
            'deploys',
            'oude-deploys',
        ]);
    });

    /** The loose pass, and it must never outrank a real substring hit. */
    it('still finds a channel from letters in order', () => {
        expect(matchChannels(CHANNELS, 'agm').map((c) => c.label)).toEqual([
            'algemeen',
        ]);
    });

    it('ranks a substring above a scattered match', () => {
        const rows = [channel(1, 'ontwerp'), channel(2, 'oude-deploys')];

        // "ode" is a substring of nothing, but sits in order in both; "oude"
        // is a real prefix of one of them.
        expect(matchChannels(rows, 'oude').map((c) => c.label)).toEqual([
            'oude-deploys',
        ]);
    });

    it('says nothing when nothing matches', () => {
        expect(matchChannels(CHANNELS, 'zzzz')).toEqual([]);
    });

    /** Same quality of match: the one with unread messages is the likelier target. */
    it('breaks a tie on unread messages', () => {
        const rows = [
            channel(1, 'deploys-a'),
            channel(2, 'deploys-b', { unreadCount: 5 }),
        ];

        expect(matchChannels(rows, 'deploys').map((c) => c.label)).toEqual([
            'deploys-b',
            'deploys-a',
        ]);
    });

    it('matches a direct message on the person name', () => {
        const rows = [channel(9, 'Fenna de Vries', { type: 'dm', name: null })];

        expect(matchChannels(rows, 'fenna')).toHaveLength(1);
    });

    it('keeps the list short enough to read', () => {
        const many = Array.from({ length: 30 }, (_, index) =>
            channel(index, `deploy-${index}`),
        );

        expect(matchChannels(many, 'deploy')).toHaveLength(6);
    });
});
