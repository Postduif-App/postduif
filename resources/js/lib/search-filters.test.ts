import { describe, expect, it } from 'vitest';

import {
    parseSearchQuery,
    trailingFilter,
    withFilter,
} from '@/lib/search-filters';

describe('parseSearchQuery', () => {
    it('takes nothing out of a plain query', () => {
        expect(parseSearchQuery('wachtwoord van fenna')).toEqual({
            channel: null,
            from: null,
            terms: 'wachtwoord van fenna',
        });
    });

    it('lifts the channel out and leaves the rest', () => {
        expect(parseSearchQuery('in:algemeen wachtwoord')).toEqual({
            channel: 'algemeen',
            from: null,
            terms: 'wachtwoord',
        });
    });

    it('keeps a channel name that has a dash in it whole', () => {
        expect(parseSearchQuery('in:klant-24 offerte').channel).toBe(
            'klant-24',
        );
    });

    it('does not mind where in the sentence the filter sits', () => {
        // Somebody adding a filter to a query they already typed should not
        // have to move it to the front.
        expect(parseSearchQuery('wachtwoord in:algemeen van fenna')).toEqual({
            channel: 'algemeen',
            from: null,
            terms: 'wachtwoord van fenna',
        });
    });

    it('takes both filters at once', () => {
        expect(parseSearchQuery('in:algemeen from:fenna offerte')).toEqual({
            channel: 'algemeen',
            from: 'fenna',
            terms: 'offerte',
        });
    });

    it('swallows the # and @ people type because they see them', () => {
        expect(parseSearchQuery('in:#algemeen from:@fenna')).toEqual({
            channel: 'algemeen',
            from: 'fenna',
            terms: '',
        });
    });

    it('lets the last of a repeated filter win', () => {
        // Two channels cannot both be the one being searched, and taking the
        // last is what changing your mind looks like when you type it.
        expect(
            parseSearchQuery('in:algemeen in:klant-24 offerte').channel,
        ).toBe('klant-24');
    });

    it('leaves a filter that has no value yet alone', () => {
        // Somebody mid-keystroke. It must not swallow the colon and it must not
        // become a filter for the empty string.
        expect(parseSearchQuery('in: wachtwoord')).toEqual({
            channel: null,
            from: null,
            terms: 'in: wachtwoord',
        });
    });

    it('does not read a colon inside a word as a filter', () => {
        expect(parseSearchQuery('https://voorbeeld.nl/in:algemeen')).toEqual({
            channel: null,
            from: null,
            terms: 'https://voorbeeld.nl/in:algemeen',
        });
    });

    it('leaves a trailing full stop out of the name', () => {
        expect(parseSearchQuery('kijk in:algemeen.').channel).toBe('algemeen');
    });

    it('reads a filter whatever case it was typed in', () => {
        expect(parseSearchQuery('IN:Algemeen offerte')).toEqual({
            channel: 'algemeen',
            from: null,
            terms: 'offerte',
        });
    });

    it('does not glue the words on either side together', () => {
        expect(parseSearchQuery('rood in:algemeen blauw').terms).toBe(
            'rood blauw',
        );
    });
});

describe('withFilter', () => {
    it('puts a filter in front, ready to keep typing after', () => {
        expect(withFilter('', 'in', 'algemeen')).toBe('in:algemeen ');
    });

    it('keeps what was already typed', () => {
        expect(withFilter('offerte', 'in', 'algemeen')).toBe(
            'in:algemeen offerte',
        );
    });

    it('swaps one channel for another rather than adding a second', () => {
        expect(withFilter('in:algemeen offerte', 'in', 'klant-24')).toBe(
            'in:klant-24 offerte',
        );
    });

    it('takes a filter back out', () => {
        expect(withFilter('in:algemeen offerte', 'in', null)).toBe('offerte');
    });

    it('leaves the other filter where it was', () => {
        expect(withFilter('from:fenna offerte', 'in', 'algemeen')).toBe(
            'in:algemeen from:fenna offerte',
        );
    });
});

describe('trailingFilter', () => {
    it('sees a filter with nothing after it yet', () => {
        // The moment somebody most wants to be shown what they can pick.
        expect(trailingFilter('in:')).toEqual({ name: 'in', value: '' });
    });

    it('sees what has been typed so far', () => {
        expect(trailingFilter('offerte in:alge')).toEqual({
            name: 'in',
            value: 'alge',
        });
    });

    it('stops once the reader has moved on', () => {
        // A completed filter with words after it is settled; suggesting on it
        // would put a list under a query somebody is no longer editing.
        expect(trailingFilter('in:algemeen offerte')).toBeNull();
    });

    it('ignores a filter that is not at the end', () => {
        expect(trailingFilter('in:algemeen from:fe')).toEqual({
            name: 'from',
            value: 'fe',
        });
    });

    it('says nothing about a plain query', () => {
        expect(trailingFilter('offerte')).toBeNull();
    });
});

describe('withFilter for from:', () => {
    it('fills in a handle somebody picked', () => {
        expect(withFilter('in:algemeen offe', 'from', 'fenna')).toBe(
            'from:fenna in:algemeen offe',
        );
    });

    it('replaces the handle rather than stacking a second', () => {
        // Picking a second person means changing your mind, not searching for
        // messages written by two people at once.
        expect(withFilter('from:anna offerte', 'from', 'fenna')).toBe(
            'from:fenna offerte',
        );
    });
});
