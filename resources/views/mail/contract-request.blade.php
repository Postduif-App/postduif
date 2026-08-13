{{--
    The layout, and nothing else. Every sentence in this mail is decided by
    RenderMailTemplate — ours and the workspace's alike — so what is left here
    is where the pieces sit and the one thing that is not negotiable: the
    button. It is drawn between the two halves of the text whatever the
    template said, which is what makes "de knop blijft altijd in beeld" true
    even for somebody who deleted the placeholder.

    Echoed unescaped because these are markdown source, not values. Anything a
    person typed has already had its angle brackets defused a layer up — see
    RenderMailTemplate::defuse — so a workspace can write **vet** without also
    being able to write a link that looks like our button.
--}}
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
