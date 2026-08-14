import { describe, expect, it } from 'vitest';

import { urlBase64ToUint8Array } from '@/lib/push';

describe('the VAPID key conversion', () => {
    it('decodes plain base64', () => {
        expect(Array.from(urlBase64ToUint8Array('AAECAw=='))).toEqual([
            0, 1, 2, 3,
        ]);
    });

    /** Every VAPID generator prints the key without its padding. */
    it('restores missing padding', () => {
        expect(Array.from(urlBase64ToUint8Array('AAECAw'))).toEqual([
            0, 1, 2, 3,
        ]);
    });

    /**
     * The URL-safe alphabet is the whole reason this function exists: '-' and
     * '_' stand in for '+' and '/', and atob() refuses both.
     */
    it('translates the URL-safe alphabet', () => {
        expect(Array.from(urlBase64ToUint8Array('--__'))).toEqual(
            Array.from(urlBase64ToUint8Array('++//')),
        );
        expect(Array.from(urlBase64ToUint8Array('-_'))).toEqual([251]);
    });

    /** A real key is 65 bytes: an uncompressed P-256 point behind its 0x04 tag. */
    it('produces the 65 bytes a server key has', () => {
        const key =
            'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';

        const bytes = urlBase64ToUint8Array(key);

        expect(bytes).toHaveLength(65);
        expect(bytes[0]).toBe(4);
    });
});
