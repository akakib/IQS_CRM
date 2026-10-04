{{-- Session success/error message as an auto-hiding toast. --}}
@foreach (['success' => 'bg-green-50 text-green-800 border-green-200', 'error' => 'bg-red-50 text-red-700 border-red-200'] as $key => $classes)
    @if (session($key))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show" x-transition
            class="fixed right-4 top-16 z-[80] max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg {{ $classes }}">
            {{ session($key) }}
        </div>
    @endif
@endforeach
