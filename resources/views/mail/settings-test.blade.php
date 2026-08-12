<x-mail::message>
# {{ __('mail.test.heading') }}

{!! __('mail.test.intro', ['workspace' => $workspace->name]) !!}

{{ __('mail.test.sender') }}

{{ __('mail.closing') }}<br>
{{ config('app.name') }}
</x-mail::message>
