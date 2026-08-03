<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{--
            The dove, in the huisstijl. Three files because browsers pick
            differently: the .ico carries 16/32/48 for the ones that still want
            it, the .svg is what anything modern prefers and scales cleanly, and
            iOS reads only the apple-touch-icon — which is full-bleed on purpose,
            because iOS rounds the corners itself and a pre-rounded source
            leaves a dark halo where its mask does not line up.
        --}}
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        {{-- Ink, so the browser chrome matches the icon rather than fighting it. --}}
        <meta name="theme-color" content="#16160F">

        {{--
            Only the face this workspace actually reads in. Loading all three
            bundled families would mean two of them are downloaded and
            preloaded for nothing on every single page.
        --}}
        @if ($page['props']['theme']['font'] ?? null)
            @fonts([$page['props']['theme']['font']])
        @endif

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        {{--
            The workspace theme, printed after the bundled stylesheet so its
            variables win, and server-side so the first paint is already in the
            right accent instead of flashing the default one. React keeps this
            same element up to date once it takes over — see workspace-theme.tsx.
        --}}
        <style id="workspace-theme">{!! $page['props']['theme']['css'] ?? '' !!}</style>

        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
