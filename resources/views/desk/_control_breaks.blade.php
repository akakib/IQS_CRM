{{-- Control room popup: today's breaks of one person (those that count towards the limit). --}}
@if ($rows->isEmpty())
    <p class="py-10 text-center text-sm text-gray-500">{{ __('No break today.') }}</p>
@else
    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
        @foreach ($rows as $b)
            <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <span class="min-w-0">
                    <span class="block font-medium text-gray-900">{{ $b->reason ?? __('Break') }}</span>
                    <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($b->started_at)->format('g:i A') }} – {{ $b->ended_at ? \Illuminate\Support\Carbon::parse($b->ended_at)->format('g:i A') : __('still on break') }}@if ($b->auto_closed) · {{ __('closed by the system') }}@endif</span>
                </span>
                <span class="shrink-0 tabular-nums text-gray-700">{{ (int) $b->minutes }} {{ __('min') }}</span>
            </div>
        @endforeach
    </div>
    @if ($rows->hasPages())<div class="mt-4" data-pages>{{ $rows->links() }}</div>@endif
@endif
