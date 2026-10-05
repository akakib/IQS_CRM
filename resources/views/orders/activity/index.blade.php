@php
    $range = fn (int $days) => route('orders.activity', array_filter(['from' => today()->subDays($days - 1)->toDateString(), 'to' => today()->toDateString(), 'staff' => $filters['staff']]));
    $isRange = fn (int $days) => $filters['from'] === today()->subDays($days - 1)->toDateString() && $filters['to'] === today()->toDateString();
    $withStaff = fn (?string $staff) => route('orders.activity', array_filter(['from' => $filters['from'], 'to' => $filters['to'], 'staff' => $staff]));
@endphp

<x-layouts.app :heading="__('Order activity')">
    <div x-data="{
            wide: window.matchMedia('(min-width: 768px)').matches,
            col: 'waiting', open: null, loading: false, stamp: @js($refreshedAt->format('g:i:s A')),
            init() {
                window.matchMedia('(min-width: 768px)').addEventListener('change', e => this.wide = e.matches);
                setInterval(() => { if (!this.open && !document.hidden) this.refresh() }, 30000);
                this.$el.addEventListener('click', e => { const b = e.target.closest('[data-more]'); if (b) this.more(b) });
            },
            async refresh() {
                if (this.loading) return;
                this.loading = true;
                try {
                    const r = await fetch(location.href, { headers: { 'X-Board': '1', Accept: 'text/html' } });
                    if (r.ok) { this.$refs.board.innerHTML = await r.text(); this.stamp = this.$refs.board.firstElementChild?.dataset.refreshed || this.stamp }
                } catch (e) {}
                this.loading = false;
            },
            async more(button) {
                button.disabled = true;
                const url = new URL(location.href);
                url.searchParams.set('column', button.dataset.more);
                url.searchParams.set('page', button.dataset.page);
                try {
                    const r = await fetch(url, { headers: { Accept: 'text/html' } });
                    if (r.ok) { button.insertAdjacentHTML('beforebegin', await r.text()); button.remove(); return }
                } catch (e) {}
                button.disabled = false;
            },
        }" @open-order.window="open = $event.detail">

        {{-- Filters: dates (when the order came in) and who holds it. --}}
        <div class="mb-4 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ $range(1) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $isRange(1), 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $isRange(1)])>{{ __('Today') }}</a>
                <a href="{{ $range(7) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $isRange(7), 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $isRange(7)])>{{ __('7 days') }}</a>
                <a href="{{ $range(30) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $isRange(30), 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $isRange(30)])>{{ __('30 days') }}</a>
                <form method="GET" x-ref="dates" class="flex flex-wrap items-center gap-2">
                    @if ($filters['staff'])<input type="hidden" name="staff" value="{{ $filters['staff'] }}">@endif
                    <x-date-input name="from" :value="$filters['from']" :placeholder="__('From')" @date-change="$nextTick(() => $refs.dates.requestSubmit())" />
                    <span class="text-sm text-gray-400">{{ __('to') }}</span>
                    <x-date-input name="to" :value="$filters['to']" :placeholder="__('To')" @date-change="$nextTick(() => $refs.dates.requestSubmit())" />
                </form>
                <a href="{{ route('orders.activity', ['from' => '', 'to' => '']) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>

                <div class="ml-auto flex items-center gap-2">
                    <span class="hidden text-xs text-gray-500 sm:inline">{{ __('Updated') }} <span x-text="stamp"></span></span>
                    <button type="button" @click="refresh()" :disabled="loading"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60">
                        <svg class="h-4 w-4" :class="loading && 'animate-spin'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('Refresh') }}
                    </button>
                </div>
            </div>

            {{-- Who holds it --}}
            <div class="flex gap-2 overflow-x-auto pb-1">
                <a href="{{ $withStaff(null) }}" @class(['shrink-0 rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary-soft font-medium text-primary' => ! $filters['staff'], 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $filters['staff']])>{{ __('Everyone') }}</a>
                @foreach ($staff as $u)
                    <a href="{{ $withStaff((string) $u->id) }}" @class(['flex shrink-0 items-center gap-1.5 rounded-full border py-1 pl-1 pr-3 text-sm', 'border-primary bg-primary-soft font-medium text-primary' => $filters['staff'] === (string) $u->id, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $filters['staff'] !== (string) $u->id])>
                        <x-avatar :name="$u->name" :photo="$u->photo_path" size="sm" /> {{ $u->name }}
                    </a>
                @endforeach
                <a href="{{ $withStaff('none') }}" @class(['shrink-0 rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary-soft font-medium text-primary' => $filters['staff'] === 'none', 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $filters['staff'] !== 'none'])>{{ __('Nobody') }}</a>
            </div>
        </div>

        <div x-ref="board">
            @include('orders.activity._board')
        </div>
        <p class="text-xs text-gray-500">{{ __('Delivered, returned and cancelled orders are in') }} <a href="{{ route('orders.index') }}" class="text-primary hover:underline">{{ __('All orders') }}</a>.</p>

        {{-- The order in a large window: everything Order management shows, with every action. Closes only with Close; the board refreshes after. --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak class="fixed inset-0 z-[120] flex items-stretch justify-center bg-black/50 sm:items-center sm:p-6" role="dialog" aria-modal="true">
                <div class="flex h-full w-full max-w-6xl flex-col overflow-hidden bg-white shadow-2xl sm:h-[92vh] sm:rounded-2xl">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Order') }}</h3>
                        <button type="button" @click="open = null; refresh()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Close') }}</button>
                    </div>
                    <template x-if="open">
                        <iframe :src="@js(route('desk.index', ['embed' => 1])) + '&order=' + open" title="{{ __('Order') }}" class="min-h-0 w-full flex-1 border-0 bg-gray-50"></iframe>
                    </template>
                </div>
            </div>
        </template>
    </div>
</x-layouts.app>
