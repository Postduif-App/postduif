import { describe, expect, it } from 'vitest';

import { connectionNotice } from '@/lib/connection-notice';

describe('the socket notice', () => {
    it('says nothing while the socket is working', () => {
        expect(connectionNotice('connected', true)).toBe('none');
        expect(connectionNotice('connected', false)).toBe('none');
    });

    /** The ordinary handshake of a page load, over before anybody looks up. */
    it('stays quiet during the first connect', () => {
        expect(connectionNotice('connecting', false)).toBe('none');
    });

    /** The same word, once a working socket has dropped, means trouble. */
    it('speaks up when a socket that worked is connecting again', () => {
        expect(connectionNotice('connecting', true)).toBe('offline');
    });

    it('speaks up when the connector has run out of attempts', () => {
        expect(connectionNotice('failed', false)).toBe('offline');
        expect(connectionNotice('failed', true)).toBe('offline');
    });

    it('speaks up when the socket is down', () => {
        expect(connectionNotice('disconnected', true)).toBe('offline');
        expect(connectionNotice('reconnecting', true)).toBe('offline');
    });
});
