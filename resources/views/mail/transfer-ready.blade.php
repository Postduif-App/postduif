<x-mail::message>
# {{ __('mail.transfer.heading') }}

{{ trans_choice('mail.transfer.intro', $files->count(), ['sender' => $senderName ?? $workspaceName]) }}

@if ($transfer->message)
> {{ $transfer->message }}
@endif

@foreach ($files as $file)
- {{ $file->file_name }}
@endforeach

<x-mail::button :url="$url">
{{ __('mail.transfer.button') }}
</x-mail::button>

{{ __('mail.transfer.expires', ['date' => $transfer->expires_at->translatedFormat('j F Y')]) }}

{{ __('mail.closing') }}<br>
{{ config('app.name') }}
</x-mail::message>
