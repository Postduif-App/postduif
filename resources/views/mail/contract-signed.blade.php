{{-- The same layout as the request, and for the same reasons — see there. --}}
<x-mail::message>
# {!! $heading !!}

{!! $before !!}

<x-mail::button :url="$url">
{!! $buttonLabel !!}
</x-mail::button>

@if ($after !== '')
{!! $after !!}
@endif
</x-mail::message>
