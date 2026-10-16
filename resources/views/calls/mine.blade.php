@php
    use App\Services\Calls\CallListService;
    use App\Support\Permissions\Mask;
    $phone = fn ($p) => $seePhone ? $p : Mask::value($p, 'customer_contact');
@endphp

<x-layouts.app :heading="__('My calls')">
    <div class="space-y-4" x-data="{
            item: { open: false, loading: false, error: null, d: {} },
            async openItem(id) {
                this.item = { open: true, loading: true, error: null, d: {} };
                try {
                    const r = await fetch(@js(route('calls.mine.item', 0)).replace(/\/0$/, '/' + id), { headers: { Accept: 'application/json' } });
                    if (! r.ok) throw new Error();
                    this.item.d = await r.json();
                } catch (e) { this.item.error = @js(__('Could not open it. Check the connection and try again.')) }
                this.item.loading = false;
            },
        }">

        {{-- The lists given to me. --}}
        <div class="grid gap-3 {{ $lists->count() > 1 ? 'md:grid-cols-2' : '' }}">
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
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center md:col-span-2">
                    <p class="text-sm font-medium text-gray-700">{{ __('No call list is given to you') }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ __('A manager gives call lists under Call lists.') }}</p>
                </div>
            @endforelse
        </div>

        {{-- Today, at a glance. --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Calls today') }}</p><p class="text-lg font-semibold tabular-nums">{{ $today['calls'] }}</p></div>
            <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Talked') }}</p><p class="text-lg font-semibold tabular-nums">{{ $today['talked'] }}</p></div>
            <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Orders') }}</p><p class="text-lg font-semibold tabular-nums">{{ $today['orders'] }}</p></div>
            <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Satisfaction') }}</p><p class="text-lg font-semibold tabular-nums">{{ $today['satisfaction'] === null ? '-' : $today['satisfaction'].'%' }}</p></div>
        </div>

        {{-- Numbers I put off for later. --}}
        @if ($callBacks->isNotEmpty())
            <div class="rounded-2xl border border-amber-200 bg-white p-4">
                <p class="mb-2 text-sm font-semibold text-amber-800">{{ __('Call-backs') }}</p>
                @foreach ($callBacks as $b)
                    <button type="button" @click="openItem({{ $b->id }})" class="flex w-full flex-wrap items-baseline justify-between gap-2 border-t border-gray-100 py-2 text-left text-sm first:border-0 hover:text-primary">
                        <span><span class="font-medium text-gray-900">{{ $b->name }}</span> <span class="font-mono text-xs text-gray-500">{{ $phone($b->phone) }}</span></span>
                        <span class="text-xs {{ $b->next_at && \Illuminate\Support\Carbon::parse($b->next_at)->isPast() ? 'font-medium text-amber-700' : 'text-gray-500' }}">
                            {{ $b->next_at && \Illuminate\Support\Carbon::parse($b->next_at)->isPast() ? __('Due now') : __('Comes back :d', ['d' => \Illuminate\Support\Carbon::parse($b->next_at)->format('d M, g:i A')]) }} · {{ $b->list }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- The history has its own page: the calling stays in focus here. --}}
        <a href="{{ route('calls.history') }}" class="flex items-center justify-between gap-2 rounded-2xl border border-gray-200 bg-white p-4 text-sm font-semibold text-gray-900 hover:border-primary">
            <span>{{ __('My call history') }}</span><span class="text-primary">{{ __('Open') }} &rsaquo;</span>
        </a>

        @include('calls._item-popup')
    </div>
</x-layouts.app>
