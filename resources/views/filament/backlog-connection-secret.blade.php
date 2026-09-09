{{--
    A secret belonging to a BacklogConnection, shown exactly once — the same
    trade webhook-token.blade.php makes for a Webhook's posting URL. Reached
    only through an action on the workspace's relation manager, which is
    admin-only.
--}}
<div class="space-y-3">
    @if ($value === null)
        <p class="text-sm">Er is niets (meer) op te vragen.</p>
    @else
        @isset($url)
            <div>
                <p class="mb-1 text-sm font-medium">Webhook-URL</p>
                <code
                    x-data
                    x-on:click="navigator.clipboard.writeText($el.textContent.trim())"
                    class="block cursor-pointer rounded-lg bg-gray-100 p-3 font-mono text-xs break-all dark:bg-gray-800"
                    title="Klik om te kopiëren"
                >{{ $url }}</code>
            </div>
        @endisset

        <div>
            <p class="mb-1 text-sm font-medium">{{ $label }}</p>
            <code
                x-data
                x-on:click="navigator.clipboard.writeText($el.textContent.trim())"
                class="block cursor-pointer rounded-lg bg-gray-100 p-3 font-mono text-xs break-all dark:bg-gray-800"
                title="Klik om te kopiëren"
            >{{ $value }}</code>
        </div>

        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
