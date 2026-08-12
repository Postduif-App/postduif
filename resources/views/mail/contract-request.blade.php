<x-mail::message>
# {{ __('mail.contract.heading') }}

{{ __('mail.contract.intro', ['sender' => $senderName ?? $workspaceName, 'title' => $contract->title]) }}

@if ($contract->message)
> {{ $contract->message }}
@endif

<x-mail::button :url="$url">
{{ __('mail.contract.button') }}
</x-mail::button>

@if ($contract->expires_at)
{{ __('mail.contract.expires', ['date' => $contract->expires_at->translatedFormat('j F Y')]) }}
@endif

{{-- Said out loud, because the link is the whole credential and the person
     holding it has no account to fall back on. Somebody who forwards this mail
     is handing over their signature. --}}
{{ __('mail.contract.personal') }}

{{ __('mail.closing') }}<br>
{{ config('app.name') }}
</x-mail::message>
