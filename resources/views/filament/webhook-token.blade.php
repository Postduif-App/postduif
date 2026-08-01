{{--
    The posting URL of a webhook. The token travels inside it, so this view is
    only ever reached through an action on the channel record — which is
    admin-only.
--}}
<div class="space-y-3">
    @if ($url === null)
        <p class="text-sm">
            Van deze webhook is geen URL meer op te vragen: hij is gemaakt
            voordat de URL bewaard werd, of hij is ingetrokken. Genereer een
            nieuwe als deze integratie weer moet werken.
        </p>
    @else
        <code
            x-data
            x-on:click="navigator.clipboard.writeText($el.textContent.trim())"
            class="block cursor-pointer rounded-lg bg-gray-100 p-3 font-mono text-xs break-all dark:bg-gray-800"
            title="Klik om te kopiëren"
        >{{ $url }}</code>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            Stuur er een POST naartoe met een JSON-body zoals
            <code class="font-mono">{"text": "Hallo"}</code>.
        </p>
    @endif
</div>
