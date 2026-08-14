/*
 * The push worker.
 *
 * This file does one job: show notifications that arrive while the app is not
 * on screen, and take somebody to the right place when they click one. It
 * deliberately has no `fetch` handler at all — a worker that answers navigation
 * requests from a cache would serve stale Inertia pages and a stale asset
 * manifest, and the failure looks like the application half-updating rather
 * than like a caching bug. Push needs a worker; it does not need a cache.
 *
 * Served straight from public/, without a build step. Nothing here is compiled,
 * so nothing here may use imports, JSX or `import.meta.env`.
 */

/** Where the server posts and deletes subscriptions. Mirrors resources/js/lib/push.ts. */
const SUBSCRIPTION_URL = '/app/settings/notifications/push';

/*
 * Take over as soon as this file changes, rather than waiting for every tab to
 * close. A push worker has no cached responses that an open page could be
 * halfway through using, so the usual reason to wait does not apply — and the
 * usual alternative, a user who never closes their chat tab, means a fixed
 * worker would not land for weeks.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);

/**
 * A notification arrived.
 *
 * `showNotification` is not optional: on every browser that implements Web
 * Push, a push that shows nothing eventually costs the site its permission.
 * That is also why the payload is read defensively — a malformed or empty push
 * still has to end in a visible notification rather than in a thrown error.
 */
self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = {};
    }

    const title = payload.title || 'Postduif';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            icon: payload.icon || '/icon-192.png',
            badge: payload.badge || '/icon-192.png',
            /*
             * The tag collapses: a second message in the same conversation
             * replaces the first rather than stacking beside it, which is what
             * keeps a busy channel from filling somebody's notification centre.
             * `renotify` is what makes that replacement still buzz — without it
             * a collapsed notification updates in silence.
             */
            tag: payload.tag || 'postduif',
            renotify: true,
            data: { url: payload.url || '/app' },
        }),
    );
});

/**
 * Somebody clicked a notification.
 *
 * The window hunt is the point. `clients.openWindow()` on its own opens a fresh
 * tab every single time, so a person who clicks five notifications over a
 * morning ends up with five copies of the same application. Instead: look for a
 * window this origin already owns, send it to the right URL, and focus it. Only
 * when there is genuinely nothing open does a new window get created.
 *
 * `includeUncontrolled` matters here — a tab loaded before this worker first
 * activated is not controlled by it, and without the flag it would be invisible
 * to the search and get a duplicate tab anyway.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(
        (event.notification.data && event.notification.data.url) || '/app',
        self.location.origin,
    );

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => {
                const existing = windows.find(
                    (client) =>
                        new URL(client.url).origin === self.location.origin,
                );

                if (!existing) {
                    return self.clients.openWindow(target.href);
                }

                /*
                 * Navigate first, focus second, and tolerate a navigate that is
                 * refused: `client.navigate()` rejects on a window the worker
                 * does not control, and in that case a focused window on the
                 * wrong page still beats a brand new tab.
                 */
                return existing
                    .navigate(target.href)
                    .catch(() => existing)
                    .then((client) => (client || existing).focus());
            }),
    );
});

/**
 * The browser rotated this device's endpoint.
 *
 * Push endpoints are not permanent. Browsers replace them — on their own
 * schedule, and without telling the page — and every rotation silently breaks
 * the subscription the server holds. Without this handler people simply stop
 * receiving notifications and nothing anywhere reports a problem.
 *
 * The key comes from `event.oldSubscription`, because a static file has no
 * access to the build's `VITE_VAPID_PUBLIC_KEY`. Re-subscribing with a
 * different key than the original would produce an endpoint the server cannot
 * sign for, so reusing the old subscription's key is not a shortcut — it is the
 * only correct source here.
 *
 * The POST is best effort. Laravel wants an X-XSRF-TOKEN header back, and only
 * Chromium lets a worker read cookies (`cookieStore`); in Firefox this request
 * is expected to be rejected. That is why resources/js/lib/push.ts re-posts the
 * current endpoint whenever the app is next opened — between the two, a rotated
 * endpoint reaches the server either immediately or on the next visit, rather
 * than never.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
    const key =
        event.oldSubscription &&
        event.oldSubscription.options &&
        event.oldSubscription.options.applicationServerKey;

    if (!key) {
        return;
    }

    event.waitUntil(
        self.registration.pushManager
            .subscribe({ userVisibleOnly: true, applicationServerKey: key })
            .then((subscription) => sendSubscription(subscription))
            .catch(() => undefined),
    );
});

/**
 * Which payload encryption this browser understands.
 *
 * Everything current says `aes128gcm`; the older `aesgcm` still exists in the
 * field, and the server has to encrypt in whichever one this device speaks.
 */
function contentEncoding() {
    const supported = self.PushManager && self.PushManager.supportedContentEncodings;

    return supported && supported.length ? supported[0] : 'aes128gcm';
}

/** Read Laravel's CSRF cookie, where the browser allows a worker to see cookies at all. */
function csrfToken() {
    if (!self.cookieStore) {
        return Promise.resolve(null);
    }

    return self.cookieStore
        .get('XSRF-TOKEN')
        .then((cookie) => (cookie ? decodeURIComponent(cookie.value) : null))
        .catch(() => null);
}

/** Hand a freshly rotated subscription to the server, in the shape the API expects. */
function sendSubscription(subscription) {
    const json = subscription.toJSON();

    return csrfToken().then((token) =>
        fetch(SUBSCRIPTION_URL, {
            method: 'POST',
            credentials: 'include',
            headers: Object.assign(
                {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                token ? { 'X-XSRF-TOKEN': token } : {},
            ),
            body: JSON.stringify({
                endpoint: json.endpoint,
                keys: json.keys,
                content_encoding: contentEncoding(),
            }),
        }),
    );
}
