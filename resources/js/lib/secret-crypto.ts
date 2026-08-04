/**
 * Encrypting a secret so that our own server cannot read it.
 *
 * The key is made here, used here, and never sent anywhere. It leaves the
 * browser exactly once — written into the fragment of the link the sender
 * copies — and browsers do not put the fragment in the request, so it reaches
 * neither our access logs nor any proxy along the way. What the server stores is
 * ciphertext it holds no key for.
 *
 * AES-GCM because it authenticates as well as encrypts: a ciphertext somebody
 * tampered with fails to decrypt rather than quietly producing different words.
 * 256-bit keys, and a fresh nonce per secret — each key encrypts exactly one
 * message in its life, which is the condition GCM asks for.
 */

/** 12 bytes, which is the nonce size AES-GCM is specified around. */
const IV_BYTES = 12;

const ALGORITHM = 'AES-GCM';

/**
 * Base64 without the characters that need escaping in a URL.
 *
 * The key ends up in a fragment, where "+" and "/" survive but are a standing
 * invitation for something in between to re-encode them. The ciphertext takes
 * the same treatment purely so there is one encoding in this file rather than
 * two.
 */
function toBase64Url(bytes: Uint8Array): string {
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
    const padded = value
        .replace(/-/g, '+')
        .replace(/_/g, '/')
        .padEnd(Math.ceil(value.length / 4) * 4, '=');

    const binary = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

/** Plain base64, which is what the two server columns hold. */
function toBase64(bytes: Uint8Array): string {
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary);
}

function fromBase64(value: string): Uint8Array<ArrayBuffer> {
    const binary = atob(value);
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

export interface SealedSecret {
    /** Base64, for the column of the same name. */
    ciphertext: string;
    /** Base64 of 12 bytes, which is 16 characters — the server checks that. */
    iv: string;
    /** Base64url, and the only copy there will ever be. */
    key: string;
}

/**
 * Encrypt a secret, and hand back the three pieces separately.
 *
 * Separately on purpose: two of them go to the server and the third must not.
 * Returning a single blob would make it far too easy for a caller to post the
 * whole thing and undo the entire point of this file.
 */
export async function sealSecret(plaintext: string): Promise<SealedSecret> {
    const key = await crypto.subtle.generateKey(
        { name: ALGORITHM, length: 256 },
        // Extractable, because the key has to be written into the link. That is
        // the one thing it is for.
        true,
        ['encrypt', 'decrypt'],
    );

    const iv = crypto.getRandomValues(new Uint8Array(IV_BYTES));

    const sealed = await crypto.subtle.encrypt(
        { name: ALGORITHM, iv },
        key,
        new TextEncoder().encode(plaintext),
    );

    const raw = await crypto.subtle.exportKey('raw', key);

    return {
        ciphertext: toBase64(new Uint8Array(sealed)),
        iv: toBase64(iv),
        key: toBase64Url(new Uint8Array(raw)),
    };
}

/**
 * Turn the ciphertext back into words, given the key from the fragment.
 *
 * Throws when the key is wrong or the ciphertext was tampered with — GCM will
 * not hand back anything it cannot vouch for. The caller draws that as "deze
 * link klopt niet", which is the only honest reading: there is nobody to ask.
 */
export async function openSecret(
    ciphertext: string,
    iv: string,
    key: string,
): Promise<string> {
    const imported = await crypto.subtle.importKey(
        'raw',
        fromBase64Url(key),
        { name: ALGORITHM },
        false,
        ['decrypt'],
    );

    const opened = await crypto.subtle.decrypt(
        { name: ALGORITHM, iv: fromBase64(iv) },
        imported,
        fromBase64(ciphertext),
    );

    return new TextDecoder().decode(opened);
}

/** How the key rides along in a link, and how it is read back off one. */
export const KEY_FRAGMENT = 'k';

export function linkWithKey(url: string, key: string): string {
    return `${url}#${KEY_FRAGMENT}=${key}`;
}

/**
 * The key out of the current address bar, or null when there is none.
 *
 * Read from location.hash, which is the one part of a URL the browser keeps to
 * itself — it is never sent with the request, which is the whole reason the key
 * travels there.
 */
export function keyFromFragment(hash: string): string | null {
    const match = new RegExp(`(?:^#|&)${KEY_FRAGMENT}=([A-Za-z0-9_-]+)`).exec(
        hash,
    );

    return match === null ? null : match[1];
}
