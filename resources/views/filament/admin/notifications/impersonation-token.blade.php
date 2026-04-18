@props(['token' => '', 'user' => null])

<div class="space-y-2" x-data="{ copied: false }">
    <p class="text-sm">
        A 1-hour API token was generated for
        <strong>{{ $user?->email }}</strong>.
        This is shown once &mdash; save it now.
    </p>

    <pre class="font-mono text-xs bg-gray-900 text-gray-100 rounded p-2 whitespace-pre-wrap break-all"
         x-ref="tok">{{ $token }}</pre>

    <button type="button"
            class="text-xs underline"
            x-on:click="navigator.clipboard.writeText($refs.tok.innerText); copied = true; setTimeout(() => copied = false, 2000)">
        <span x-show="!copied">Copy to clipboard</span>
        <span x-show="copied" x-cloak>Copied!</span>
    </button>
</div>
