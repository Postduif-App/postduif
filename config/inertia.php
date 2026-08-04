<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        'enabled' => true,
        /*
         * Loopback when the renderer runs beside the application, which is what
         * `composer dev` does. In Docker it does not: 127.0.0.1 in the app
         * container is a different machine as far as the renderer is concerned.
         */
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        /*
         * While Vite is hot, the renderer is part of the dev server and Inertia
         * finds it by reading public/hot. That file holds the address the
         * *browser* uses — under Docker the app has to be told the other one.
         */
        'hot_url' => env('INERTIA_SSR_HOT_URL'),
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
