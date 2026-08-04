import { mergeConfig } from 'vite';

import base from './vite.config';

/**
 * The server-side render bundle for the production image.
 *
 * Vite leaves anything from node_modules out of an SSR build by default and
 * imports it at runtime, which would mean shipping node_modules beside the
 * bundle — a few hundred megabytes of dependencies in an image whose only job
 * is to turn a page object into HTML. `noExternal` bundles them in instead, so
 * the renderer needs a Node binary and nothing else.
 *
 * Used by the `ssr` stage in the Dockerfile. `npm run build:ssr` on your own
 * machine still uses the plain config: there, node_modules is right there.
 */
export default mergeConfig(base, {
    ssr: {
        noExternal: true,
    },
});
