import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

/*
 * Vitest bouwt intern een Vite dev-server om de tests te bundelen, en de
 * laravel-plugin weigert dienst zodra CI in de omgeving staat — een guard tegen
 * een HMR-server in een deployscript, die het verschil met een testrun niet kan
 * zien. Vandaar dat de suite alleen op GitHub omviel en lokaal groen bleef.
 *
 * De unit-tests raken de asset-pipeline niet: ze testen resources/js/lib. Dus
 * blijft de hele pipeline er onder vitest buiten — plugin, fonts en de
 * route-generator die anders per run artisan aanroept. Alleen de '@'-alias die
 * de plugin normaal meelevert zetten we zelf, want de tests importeren erlangs.
 */
const underTest = process.env.VITEST !== undefined;

export default defineConfig({
    resolve: {
        alias: underTest
            ? {
                  '@': fileURLToPath(
                      new URL('./resources/js', import.meta.url),
                  ),
              }
            : undefined,
    },
    plugins: underTest
        ? [
              react({
                  babel: {
                      plugins: ['babel-plugin-react-compiler'],
                  },
              }),
          ]
        : [
              laravel({
                  input: ['resources/css/app.css', 'resources/js/app.tsx'],
                  refresh: true,
                  // Every face a workspace can pick in its theme settings is bundled
                  // here, at the same weights: the choice has to be instant, and a
                  // font fetched from somebody else's CDN at render time would be
                  // neither instant nor private.
                  fonts: [
                      bunny('Instrument Sans', {
                          weights: [400, 500, 600],
                      }),
                      bunny('Inter', {
                          weights: [400, 500, 600],
                      }),
                      bunny('Figtree', {
                          weights: [400, 500, 600],
                      }),
                      bunny('Ubuntu', {
                          weights: [400, 500, 700],
                      }),
                      bunny('JetBrains Mono', {
                          weights: [400, 500, 600],
                      }),
                      /*
                       * The two faces of the house style. Bundled here rather than
                       * pulled from Google Fonts as the design file does — the reason
                       * above applies doubly to a page whose whole argument is that
                       * nobody is reading along.
                       */
                      bunny('IBM Plex Mono', {
                          weights: [400, 500, 600, 700],
                      }),
                      bunny('IBM Plex Sans', {
                          weights: [400, 500, 600],
                      }),
                      /*
                       * The one script face, and it is here for a single box: the
                       * typed alternative to drawing a signature. Bundled like the
                       * rest rather than fetched at render time, and that matters
                       * more for this one than for the others — the browser draws the
                       * typed name into a canvas and uploads the result, so a face
                       * that had not arrived yet would silently store a signature in
                       * the wrong lettering. See signature-pad.tsx, which waits for
                       * it before it will draw anything.
                       */
                      bunny('Caveat', {
                          weights: [500],
                      }),
                  ],
              }),
              inertia(),
              react({
                  babel: {
                      plugins: ['babel-plugin-react-compiler'],
                  },
              }),
              tailwindcss(),
              wayfinder({
                  formVariants: true,
              }),
          ],
    build: {
        rollupOptions: {
            output: {
                /*
                 * Assets houden hun eigen extensie, en één asset heeft daar
                 * last van: de pdf.js-worker heet .mjs. Een nginx zonder
                 * MIME-regel voor die extensie serveert hem als
                 * application/octet-stream, en daar start geen browser een
                 * module-worker uit op — zeker niet met de nosniff-header die
                 * deze applicatie meestuurt. Zie contract-pdf.tsx.
                 *
                 * De inhoud is gewoon JavaScript, dus schrijven we hem als .js
                 * weg. Dan hoeft geen enkele webserver iets te weten: de
                 * standaard MIME-map heeft .js al.
                 */
                assetFileNames: (asset) => {
                    const source = asset.names?.[0] ?? asset.name ?? '';

                    return source.endsWith('.mjs')
                        ? 'assets/[name]-[hash].js'
                        : 'assets/[name]-[hash][extname]';
                },
            },
        },
    },
});
