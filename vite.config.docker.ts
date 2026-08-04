import { mergeConfig } from 'vite';

import base from './vite.config';

/**
 * The Vite config for the development container, used by the `vite` service in
 * compose.yaml. It exists as a separate file so that vite.config.ts stays
 * exactly what somebody running the app on their own machine needs.
 *
 * Two things differ inside a container:
 *
 * - the dev server has to listen on every interface, or the published port
 *   forwards to nothing;
 * - laravel-vite-plugin writes the address it is listening on into public/hot,
 *   and `0.0.0.0` is not an address a browser can be relied on to interpret.
 *   `hmr.host` is what the plugin uses for that file, so it also decides where
 *   the browser looks for the module graph and the websocket.
 */
export default mergeConfig(base, {
    server: {
        host: '0.0.0.0',
        port: 5173,
        /*
         * The app container asks this server to render pages — Inertia's SSR
         * lives inside the dev server, at /__inertia_ssr. That request arrives
         * with `vite` as its Host, and Vite refuses hostnames it was not told
         * about. The browser's own requests say localhost, which it allows by
         * itself.
         */
        allowedHosts: ['vite'],
        hmr: {
            host: 'localhost',
            clientPort: Number(process.env.VITE_CLIENT_PORT ?? 5173),
        },
        /*
         * File changes on the host reach the container as filesystem events on
         * Docker Desktop and OrbStack alike. If yours does not — some Linux
         * setups with an overlay in between — set VITE_USE_POLLING=1 and Vite
         * will ask instead of waiting to be told.
         */
        watch: process.env.VITE_USE_POLLING
            ? { usePolling: true, interval: 300 }
            : undefined,
    },
});
