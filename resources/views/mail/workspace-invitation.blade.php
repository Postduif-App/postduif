<x-mail::message>
# Je bent uitgenodigd

{{ $inviter->name }} nodigt je uit voor **{{ $workspace->name }}**.

@if ($isGuest)
Je doet mee als gast. Dat betekent dat je alleen de kanalen ziet waarvoor je bent
uitgenodigd — de rest van de workspace blijft buiten beeld.
@endif

@if ($channels->isNotEmpty())
Je krijgt toegang tot:

@foreach ($channels as $channel)
- #{{ $channel->name }}
@endforeach
@endif

<x-mail::button :url="$url">
Uitnodiging accepteren
</x-mail::button>

Deze link verloopt op {{ $invitation->expires_at->translatedFormat('j F Y') }}. Was
deze uitnodiging niet voor jou bedoeld? Dan hoef je niets te doen.

Groeten,<br>
{{ config('app.name') }}
</x-mail::message>
