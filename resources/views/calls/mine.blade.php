@php use App\Services\Calls\CallListService; @endphp

<x-layouts.app :heading="__('My calls')">
    <div class="mx-auto max-w-2xl space-y-3">
        @forelse ($lists as $l)
            @php $w = $l->daily_target ? min(100, round($l->today / $l->daily_target * 100)) : 0; @endphp
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-gray-900">{{ $l->name }}</p>
                        <p class="text-xs text-gray-500">{{ __(CallListService::PURPOSES[$l->purpose] ?? $l->purpose) }} · {{ trans_choice(':count number left|:count numbers left', $l->left, ['count' => number_format($l->left)]) }}</p>
                    </div>
                    <p class="shrink-0 text-right"><span class="text-xl font-semibold tabular-nums {{ $l->today >= $l->daily_target ? 'text-green-700' : 'text-gray-900' }}">{{ $l->today }}</span><span class="text-sm text-gray-500"> / {{ $l->daily_target }}</span><br><span class="text-xs text-gray-500">{{ __('today') }}</span></p>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full {{ $w >= 100 ? 'bg-green-600' : 'bg-primary' }}" style="width: {{ $w }}%"></div></div>
                @if ($l->left)
                    <a href="{{ route('calls.work', $l->id) }}" class="mt-3 block rounded-xl bg-primary px-4 py-3.5 text-center text-base font-semibold text-white hover:bg-primary-dark">{{ __('Start calling') }}</a>
                @else
                    <p class="mt-3 rounded-xl bg-gray-50 px-4 py-3 text-center text-sm text-gray-500">{{ __('Nothing to call now. Call-backs come back here when they are due.') }}</p>
                @endif
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                <p class="text-sm font-medium text-gray-700">{{ __('No call list is given to you') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('A manager gives call lists under Call lists.') }}</p>
            </div>
        @endforelse
    </div>
</x-layouts.app>
