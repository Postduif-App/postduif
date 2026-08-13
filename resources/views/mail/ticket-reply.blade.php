<x-mail::message>
{{ $body }}

@if ($author)
{{ __('mail.ticket_reply.signed', ['name' => $author, 'workspace' => $workspaceName]) }}
@endif

{{ __('mail.ticket_reply.footer', ['number' => $ticket->number]) }}
</x-mail::message>
