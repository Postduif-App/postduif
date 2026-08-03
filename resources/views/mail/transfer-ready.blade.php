<x-mail::message>
# Er staan bestanden voor je klaar

{{ $senderName ?? $workspaceName }} heeft {{ $files->count() === 1 ? 'een bestand' : $files->count() . ' bestanden' }} voor je klaargezet.

@if ($transfer->message)
> {{ $transfer->message }}
@endif

@foreach ($files as $file)
- {{ $file->file_name }}
@endforeach

<x-mail::button :url="$url">
Bestanden downloaden
</x-mail::button>

Deze link is voor jou gemaakt en verloopt op {{ $transfer->expires_at->translatedFormat('j F Y') }}.
Daarna zijn de bestanden weg. Kreeg je dit onverwacht? Dan hoef je niets te doen.

Groeten,<br>
{{ config('app.name') }}
</x-mail::message>
