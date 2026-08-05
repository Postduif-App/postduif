{{--
    The one screen an OAuth client sends somebody to.

    It belongs to the family of screens people meet from outside the
    application — logging in, accepting an invitation, downloading a file — and
    it carries the brand for the same reason those do: the client that opened
    this window put its own name in the address bar, and the only thing telling
    the reader whose account they are handing over is what this page looks like.

    Dressed rather than rewritten. The forms, the field names and the
    session-backed auth token are the package's, because they are what the
    approve and deny controllers read.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ __('mcp.authorize.title', ['client' => $client->name]) }}</title>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-mono:400,600|ibm-plex-sans:400,500,600" rel="stylesheet">

    @vite(['resources/css/app.css'])
</head>
<body class="postduif">
<div class="flex min-h-screen items-center justify-center p-6">
    <div class="w-full max-w-[30rem]">
        <div class="mb-8 flex items-center gap-2.5" style="color: var(--pd-inkt)">
            <svg viewBox="0 0 48 48" width="24" height="24" aria-hidden="true">
                <g fill="currentColor">
                    <path d="M21 23.6L1.5 21.4L19.5 32Z"/>
                    <path d="M30.5 7.6C35.6 6.6 39.6 10 39.6 14L45.6 14.6L39 18.2C42 22 42.2 30 35.8 33.4C30.2 36.4 22.4 35.4 18.8 31.6C15.6 28.2 18.6 21.4 25.6 18.6C26 13.2 27 9 30.5 7.6Z"/>
                </g>
            </svg>
            <span style="font-family: var(--pd-mono); font-weight: 600; letter-spacing: -0.02em">postduif</span>
        </div>

        <div style="background: var(--pd-wit); border: 1px solid var(--pd-zand); border-radius: 10px" class="p-8">
            <h1 class="m-0 mb-4" style="font-size: 26px; letter-spacing: -0.03em; line-height: 1.15">
                {{ __('mcp.authorize.heading', ['client' => $client->name]) }}
            </h1>

            <p class="m-0 mb-6" style="font-size: 15px; line-height: 1.6; color: var(--pd-steen)">
                {{ __('mcp.authorize.explanation') }}
            </p>

            {{--
                Which account is about to be handed over. The whole risk of this
                screen is somebody approving it while signed in as the wrong
                person, so the address is stated rather than assumed.
            --}}
            <div class="mb-6 p-4" style="background: var(--pd-papier); border-radius: 6px">
                <div style="font-family: var(--pd-mono); font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #8b8a7b">
                    {{ __('mcp.authorize.as') }}
                </div>
                <div class="mt-1" style="font-size: 15px; font-weight: 500">{{ $user->email }}</div>
            </div>

            <ul class="m-0 mb-8 grid list-none gap-2 p-0">
                @foreach ($scopes as $scope)
                    <li class="flex items-start gap-2.5" style="font-size: 14px; line-height: 1.55">
                        <span style="color: var(--pd-geel); font-family: var(--pd-mono)">●</span>
                        <span>{{ __('mcp.authorize.scope') }}</span>
                    </li>
                @endforeach

                <li class="flex items-start gap-2.5" style="font-size: 14px; line-height: 1.55; color: var(--pd-steen)">
                    <span style="color: var(--pd-geel); font-family: var(--pd-mono)">●</span>
                    <span>{{ __('mcp.authorize.limits') }}</span>
                </li>
            </ul>

            <div class="flex gap-3">
                <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="w-full"
                            style="font-family: var(--pd-mono); font-size: 14px; font-weight: 600; padding: 13px 20px; border-radius: 6px; border: 1px solid var(--pd-zand); background: var(--pd-wit); color: var(--pd-inkt); cursor: pointer">
                        {{ __('mcp.authorize.deny') }}
                    </button>
                </form>

                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                    @csrf
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="w-full"
                            style="font-family: var(--pd-mono); font-size: 14px; font-weight: 600; padding: 13px 20px; border-radius: 6px; border: 1px solid var(--pd-geel); background: var(--pd-geel); color: var(--pd-inkt); cursor: pointer">
                        {{ __('mcp.authorize.approve') }}
                    </button>
                </form>
            </div>
        </div>

        <p class="mt-6 m-0" style="font-size: 13px; line-height: 1.55; color: var(--pd-steen)">
            {{ __('mcp.authorize.revoke_hint') }}
        </p>
    </div>
</div>
</body>
</html>
