{{-- Upsell: per person, extra money from items added to website orders at the customer's request (UpsellReport).
     Earned = delivered; On the way = not finished yet; Lost = cancelled or returned. A row opens that person's orders. --}}
@php
    $money = fn ($n) => '৳'.number_format((float) $n);
    $presets = [__('Today') => [today(), today()], __('7 days') => [today()->subDays(6), today()], __('This month') => [today()->startOfMonth(), today()]];
    $state = ['won' => [__('Delivered'), 'bg-green-100 text-green-800'], 'open' => [__('On the way'), 'bg-gray-100 text-gray-700'], 'lost' => [__('Cancelled or returned'), 'bg-red-100 text-red-700']];
@endphp

<x-layouts.app :heading="$own ? __('My upsell') : __('Upsell')">
<div x-data="{ pop: false, title: '', html: '', show(ref, t) { this.title = t; this.html = this.$refs[ref].innerHTML; this.pop = true } }" @keydown.escape.window="pop = false">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach ($presets as $label => [$f, $t])
            @php $on = $from === $f->toDateString() && $to === $t->toDateString(); @endphp
            <a href="{{ route('upsell.index', ['from' => $f->toDateString(), 'to' => $t->toDateString()]) }}" @class(['inline-flex min-h-10 items-center rounded-full border px-4 text-sm', 'border-primary bg-primary text-white' => $on, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $on])>{{ $label }}</a>
        @endforeach
        <form method="GET" x-ref="range" class="flex flex-wrap items-center gap-2">
            <x-date-input name="from" :value="$from" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
            <span class="text-sm text-gray-400">{{ __('to') }}</span>
            <x-date-input name="to" :value="$to" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
        </form>
    </div>

    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-primary bg-primary-soft p-4">
            <p class="text-sm font-semibold text-gray-600">{{ __('Earned (delivered)') }}</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-gray-900">{{ $money($people->sum('won')) }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4">
            <p class="text-sm font-semibold text-gray-600">{{ __('On the way') }}</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-gray-900">{{ $money($people->sum('open')) }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4">
            <p class="text-sm font-semibold text-gray-600">{{ __('Orders with an upsell') }}</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-gray-900">{{ number_format($rows->pluck('order_id')->unique()->count()) }}</p>
            @if ($people->sum('lost') > 0)<p class="mt-1 text-xs text-red-700">{{ __('Lost: :m (cancelled or returned)', ['m' => $money($people->sum('lost'))]) }}</p>@endif
        </div>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4">
        <h2 class="mb-2 text-base font-semibold text-gray-900">{{ __('By person') }}</h2>
        @forelse ($people as $pi => $p)
            <button type="button" @click="show('p{{ $pi }}', @js($p->name))" class="flex w-full flex-wrap items-center justify-between gap-3 rounded-xl px-2 py-3 text-left hover:bg-gray-50">
                <span class="flex min-w-0 items-center gap-3">
                    <x-avatar :name="$p->name" :photo="$p->photo" size="sm" />
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900">{{ $p->name }}</span><span class="block text-xs text-gray-500">{{ trans_choice(':n order|:n orders', $p->orders, ['n' => $p->orders]) }}</span></span>
                </span>
                <span class="flex flex-wrap items-center gap-4 text-right text-sm tabular-nums">
                    <span><span class="block text-xs text-gray-500">{{ __('Earned') }}</span><b class="text-green-800">{{ $money($p->won) }}</b></span>
                    <span><span class="block text-xs text-gray-500">{{ __('On the way') }}</span>{{ $money($p->open) }}</span>
                    @if ($p->lost > 0)<span><span class="block text-xs text-gray-500">{{ __('Lost') }}</span><span class="text-red-700">{{ $money($p->lost) }}</span></span>@endif
                    <span class="font-semibold text-primary">›</span>
                </span>
            </button>
        @empty
            <p class="py-6 text-center text-sm text-gray-500">{{ __('No upsell in these dates.') }}</p>
        @endforelse
        <p class="mt-2 text-xs text-gray-500">{{ __('An upsell: on a website order, while verifying it by call, items added or more of one and the total went up. It counts for whoever made the edit.') }}</p>
    </section>

    @foreach ($people as $pi => $p)
        <template x-ref="p{{ $pi }}">
            <ul class="divide-y divide-gray-100">
                @foreach ($p->rows as $r)
                    <li class="flex items-start justify-between gap-3 py-2.5 text-sm">
                        <span class="min-w-0">
                            <a href="{{ route('orders.show', $r->order_id) }}" class="font-semibold text-primary hover:underline">{{ $r->order_no }}</a> <span class="text-gray-800">{{ $r->ship_name }}</span>
                            <span class="block text-xs text-gray-600">{{ implode(', ', $r->added) }}</span>
                            <span class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r->applied_at)->format('d M, g:i A') }} <span class="rounded-full px-2 py-0.5 font-medium {{ $state[$r->state][1] }}">{{ $state[$r->state][0] }}</span></span>
                        </span>
                        <span class="shrink-0 font-semibold tabular-nums text-gray-900">+{{ $money($r->amount_diff) }}</span>
                    </li>
                @endforeach
            </ul>
        </template>
    @endforeach

    <div x-show="pop" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/40" @click="pop = false"></div>
        <div class="relative flex max-h-[88dvh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                <h3 class="truncate text-sm font-semibold text-gray-900" x-text="title"></h3>
                <button type="button" @click="pop = false" class="text-xl leading-none text-gray-400 hover:text-gray-600" aria-label="{{ __('Close') }}">&times;</button>
            </div>
            <div class="min-h-0 overflow-y-auto px-5 py-3" x-html="html"></div>
        </div>
    </div>
</div>
</x-layouts.app>
