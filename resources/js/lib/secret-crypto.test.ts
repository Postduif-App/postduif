import { describe, expect, it } from 'vitest';

import {
    keyFromFragment,
    linkWithKey,
    openSecret,
    sealSecret,
} from './secret-crypto';

describe('sealSecret / openSecret', () => {
    it('gives back exactly what went in', async () => {
        const sealed = await sealSecret('hunter2');

        await expect(
            openSecret(sealed.ciphertext, sealed.iv, sealed.key),
        ).resolves.toBe('hunter2');
    });

    it('survives the characters a password actually contains', async () => {
        const awkward = 'p@ss "wörd" \\ / + = #frag & é🔑\nnieuwe regel';
        const sealed = await sealSecret(awkward);

        await expect(
            openSecret(sealed.ciphertext, sealed.iv, sealed.key),
        ).resolves.toBe(awkward);
    });

    /**
     * The nonce is what must never repeat across two messages under one key.
     * Here every secret gets its own key as well, so this is belt and braces —
     * and worth pinning, because a constant IV is the classic way this goes
     * quietly wrong.
     */
    it('never reuses a nonce or a key', async () => {
        const first = await sealSecret('zelfde tekst');
        const second = await sealSecret('zelfde tekst');

        expect(first.iv).not.toBe(second.iv);
        expect(first.key).not.toBe(second.key);
        expect(first.ciphertext).not.toBe(second.ciphertext);
    });

    it('is 16 characters of base64, which is what the server checks', async () => {
        const sealed = await sealSecret('x');

        expect(sealed.iv).toHaveLength(16);
    });

    /**
     * GCM authenticates as well as encrypts, so the wrong key is a failure
     * rather than different words. The retrieval screen leans on that: it can
     * only say "deze link klopt niet" because there is no quietly-wrong answer.
     */
    it('refuses the wrong key rather than returning nonsense', async () => {
        const sealed = await sealSecret('hunter2');
        const other = await sealSecret('iets anders');

        await expect(
            openSecret(sealed.ciphertext, sealed.iv, other.key),
        ).rejects.toThrow();
    });

    it('refuses a ciphertext somebody has edited', async () => {
        const sealed = await sealSecret('hunter2');

        // Flip a character in the middle, leaving the length alone.
        const tampered =
            sealed.ciphertext.slice(0, 4) +
            (sealed.ciphertext[4] === 'A' ? 'B' : 'A') +
            sealed.ciphertext.slice(5);

        await expect(
            openSecret(tampered, sealed.iv, sealed.key),
        ).rejects.toThrow();
    });
});

describe('the key in the link', () => {
    it('writes and reads back the same key', async () => {
        const { key } = await sealSecret('hunter2');
        const url = linkWithKey('https://postduif.test/geheim/01kz', key);

        expect(keyFromFragment(new URL(url).hash)).toBe(key);
    });

    it('puts the key behind the hash, where it stays out of the request', () => {
        const url = linkWithKey('https://postduif.test/geheim/01kz', 'abc123');

        // Everything before the "#" is what the browser actually sends.
        expect(url.split('#')[0]).toBe('https://postduif.test/geheim/01kz');
        expect(url).toContain('#k=abc123');
    });

    it('finds nothing in a link that carries no key', () => {
        expect(keyFromFragment('')).toBeNull();
        expect(keyFromFragment('#')).toBeNull();
        expect(keyFromFragment('#iets=anders')).toBeNull();
    });
});
