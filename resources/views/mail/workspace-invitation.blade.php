<x-mail::message>
# {{ __('mail.invitation.heading') }}

{!! __('mail.invitation.intro', ['inviter' => $inviter->name, 'workspace' => $workspace->name]) !!}

@if ($isGuest)
{{ __('mail.invitation.guest') }}
@endif

@if ($channels->isNotEmpty())
{{ __('mail.invitation.channels') }}

@foreach ($channels as $channel)
- #{{ $channel->name }}
@endforeach
@endif

<x-mail::button :url="$url">
{{ __('mail.invitation.button') }}
</x-mail::button>

{{ __('mail.invitation.expires', ['date' => $invitation->expires_at->translatedFormat('j F Y')]) }}

{{ __('mail.closing') }}<br>
{{ config('app.name') }}
</x-mail::message>
