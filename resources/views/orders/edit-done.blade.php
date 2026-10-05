{{-- Shown inside the edit popup after a save: tells the page behind it to refresh. --}}
<x-layouts.embed>
    <p class="py-16 text-center text-sm text-gray-500">{{ __('Saved.') }}</p>
    <script>window.parent.postMessage({ iqsOrderEdited: true }, window.location.origin);</script>
</x-layouts.embed>
