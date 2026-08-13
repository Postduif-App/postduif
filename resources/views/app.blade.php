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
            What a crawler and a chat client read. Rendered here rather than
            through Inertia's <Head> so it is in the first response whatever
            happens to SSR — a preview card is fetched by something that does
            not run JavaScript, and a description that depended on hydration
            would be a description nobody outside a browser ever sees.

            Only the public pages send this prop. The application behind the
            login has nothing to say to a crawler and says nothing.
        --}}
        @if ($seo = ($page['props']['seo'] ?? null))
            <meta name="description" content="{{ $seo['description'] }}">
            <link rel="canonical" href="{{ $seo['url'] }}">

            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:title" content="{{ $seo['title'] }}">
            <meta property="og:description" content="{{ $seo['description'] }}">
            <meta property="og:url" content="{{ $seo['url'] }}">
            <meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">
            {{-- Absolute, and the dimensions said out loud: a card renders
                 before the image has been fetched, and one that knows the
                 shape does not reflow when it arrives. --}}
            <meta property="og:image" content="{{ $seo['image'] }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">

            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $seo['title'] }}">
            <meta name="twitter:description" content="{{ $seo['description'] }}">
            <meta name="twitter:image" content="{{ $seo['image'] }}">

            @isset($seo['schema'])
                <script type="application/ld+json">{!! json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
            @endisset
        @endif

        {{--
            The faces this screen actually reads in: the one the workspace
            picked, plus the house style where the .postduif shell is worn.
            Loading all eight bundled families would preload twenty-three
            weights on every single page, most of them for nothing.

            Note that this list decides more than preloading — a family left
            out has no @font-face rule on the page at all. See PageFonts.
        --}}
        @if ($aliases = App\Support\PageFonts::for($page['component'], $page['props']['theme']['font'] ?? null))
            @fonts($aliases)
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
