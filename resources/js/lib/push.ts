import { mutatingHeaders } from '@/lib/csrf';

/**
 * Where subscriptions are registered and withdrawn.
 *
 * A literal path rather than a wayfinder route: these two endpoints take a JSON
 * body from `fetch()` rather than an Inertia visit, and the same path is
 * repeated inside public/sw.js — which is a static file with no imports and no
 * generated routes at all. One constant here, one there, both spelled out.
 */
const SUBSCRIPTION_URL = '/app/settings/notifications/push';

/**
 * What this browser and this person have decided about notifications.
 *
 * Five states rather than the three `Notification.permission` has, because the
 * two extra ones are the ones a settings screen actually needs to distinguish.
 * `unsupported` is a browser that cannot do this at all — no worker, no
 * PushManager, or a page served over plain HTTP — and it deserves an
 * explanation rather than a button. `subscribed` is permission *plus* a live
 * subscription for this device: permission alone is not the same as being
 * switched on, since turning notifications off here drops the subscription
 * while leaving the browser permission granted forever.
 *
 * `denied` cannot be undone from JavaScript. Asking again does nothing at all —
 * no prompt, an immediate refusal — so a screen showing this state has to send
 * somebody to their browser's own settings instead of offering a button.
 */
export type PushStatus =
    'unsupported' | 'default' | 'granted' | 'denied' | 'subscribed';

/** Why a subscribe or unsubscribe did not happen, in terms a screen can explain. */
export type PushFailure = 'unsupported' | 'no-key' | 'denied' | 'failed';

/** A failure with a cause the UI can translate, rather than a message it cannot. */
export class PushError extends Error {
    constructor(public readonly failure: PushFailure) {
        super(failure);
        this.name = 'PushError';
    }
}

/**
 * Whether this page can do push at all.
 *
 * Both halves are needed: Firefox and Chrome expose `serviceWorker` on any
 * secure origin but `PushManager` only where push is actually available, and
 * `Notification` is missing entirely on iOS Safari outside an installed
 * home-screen app. Service workers exist only on HTTPS or localhost, so this is
 * also what reports `false` on a plain-HTTP staging host.
 */
export function isPushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/**
 * The VAPID key the server signs with, as the Push API insists on receiving it.
 *
 * `pushManager.subscribe()` takes a `Uint8Array`, not the URL-safe base64
 * string that every VAPID tool prints — hand it the string and the browser
 * rejects the subscription with an error that does not mention encoding. The
 * two substitutions undo the URL-safe alphabet, and the padding is put back
 * because `atob` will not decode a base64 string whose length is not a multiple
 * of four.
 */
export function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalised = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(normalised);
    // Backed by an explicit ArrayBuffer: `subscribe()` takes a BufferSource,
    // and a plain `new Uint8Array(length)` is typed over SharedArrayBuffer too,
    // which that signature will not accept.
    const output = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i += 1) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}

/**
 * Make sure the push worker is installed, and hand back its registration.
 *
 * Scope `/` because a notification click has to be able to focus and navigate
 * any page of the application, not only the settings page the worker happened
 * to be registered from. The file lives at the root of public/ for exactly that
 * reason — a worker's scope cannot reach above its own directory.
 *
 * Registering does not ask anybody anything, so this is safe to call before a
 * permission prompt is on the table.
 */
export async function registerServiceWorker(): Promise<ServiceWorkerRegistration> {
    if (!isPushSupported()) {
        throw new PushError('unsupported');
    }

    /*
     * `updateViaCache: 'none'` rather than the default, so the update check
     * always goes to the server. public/sw.js is served by the webserver as a
     * plain static file, and a long max-age on it would otherwise pin a broken
     * worker on somebody's machine for as long as that header says.
     */
    return navigator.serviceWorker.register('/sw.js', {
        scope: '/',
        updateViaCache: 'none',
    });
}

/**
 * What the current state is, without asking anybody anything.
 *
 * Uses `getRegistration()` rather than registering, so opening a settings page
 * has no side effects at all for somebody who has never turned this on.
 */
export async function getSubscriptionState(): Promise<PushStatus> {
    if (!isPushSupported()) {
        return 'unsupported';
    }

    const permission = Notification.permission;

    if (permission !== 'granted') {
        return permission;
    }

    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    return subscription ? 'subscribed' : 'granted';
}

/**
 * Turn notifications on for this device.
 *
 * Only ever called from a click. Chrome and Firefox both penalise a site that
 * prompts on load — Firefox refuses the prompt outright without a user gesture,
 * Chrome quietly starts hiding it — and neither penalty is visible to whoever
 * wrote the code that caused it.
 *
 * The order matters: permission first, then subscribe, then tell the server.
 * Registering the subscription with the browser but failing to store it would
 * leave a device the server can never reach and the UI still calls "on", so a
 * failed POST rolls the subscription back out again.
 */
export async function subscribeToPush(): Promise<PushStatus> {
    if (!isPushSupported()) {
        throw new PushError('unsupported');
    }

    const key = import.meta.env.VITE_VAPID_PUBLIC_KEY;

    if (!key) {
        throw new PushError('no-key');
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        // 'default' means the prompt was dismissed rather than refused, but for
        // the caller both mean the same thing: nothing was turned on.
        throw new PushError(permission === 'denied' ? 'denied' : 'failed');
    }

    const registration = await registerServiceWorker();
    // A worker that is still installing has no usable pushManager yet, and a
    // first-ever subscribe would otherwise race it.
    await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(key),
        }));

    try {
        await storeSubscription(subscription);
    } catch (error) {
        await subscription.unsubscribe();

        throw error;
    }

    return 'subscribed';
}

/**
 * Turn notifications off for this device.
 *
 * The server is told first. If the browser dropped its subscription and the
 * POST that follows failed, the server would keep pushing to an endpoint that
 * no longer exists — invisible from here, and only ever discovered as a growing
 * pile of rejected deliveries.
 */
export async function unsubscribeFromPush(): Promise<PushStatus> {
    if (!isPushSupported()) {
        return 'unsupported';
    }

    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    if (!subscription) {
        return getSubscriptionState();
    }

    await request('DELETE', { endpoint: subscription.endpoint });
    await subscription.unsubscribe();

    return 'granted';
}

/**
 * Re-post the endpoint this device already has, quietly.
 *
 * The other half of the `pushsubscriptionchange` handler in public/sw.js. That
 * handler runs at rotation time but can rarely prove who it is — Laravel wants
 * the CSRF token back, and only Chromium lets a worker read cookies. This runs
 * from a page, where the cookie is readable, and re-registers whatever endpoint
 * the browser currently holds.
 *
 * It never subscribes and never prompts: with no existing subscription it does
 * nothing at all, which is what keeps it safe to call on page load.
 */
export async function syncExistingSubscription(): Promise<void> {
    if (!isPushSupported() || Notification.permission !== 'granted') {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    if (subscription) {
        await storeSubscription(subscription);
    }
}

/** Hand a subscription to the server in the shape the endpoint documents. */
async function storeSubscription(
    subscription: PushSubscription,
): Promise<void> {
    const json = subscription.toJSON();

    await request('POST', {
        endpoint: subscription.endpoint,
        keys: json.keys,
        content_encoding: contentEncoding(),
    });
}

/**
 * Which payload encryption this browser understands.
 *
 * The server has to encrypt in the scheme this device speaks. Everything
 * current answers `aes128gcm`; the older `aesgcm` is still out there, and a
 * browser too old to list anything gets the modern default.
 */
function contentEncoding(): string {
    const supported = PushManager.supportedContentEncodings;

    return supported?.length ? supported[0] : 'aes128gcm';
}

/** One JSON call to the subscription endpoint, with a thrown error on refusal. */
async function request(method: 'POST' | 'DELETE', body: object): Promise<void> {
    let response: Response;

    try {
        response = await fetch(SUBSCRIPTION_URL, {
            method,
            headers: mutatingHeaders(),
            body: JSON.stringify(body),
        });
    } catch {
        throw new PushError('failed');
    }

    if (!response.ok) {
        throw new PushError('failed');
    }
}
