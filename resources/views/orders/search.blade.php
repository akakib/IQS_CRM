@php
    use App\Support\Permissions\Mask;
    $modes = [
        'all' => [__('All'), __('Order number, phone, CN or name'), 'text'],
        'cn' => [__('CN'), __('Steadfast CN or tracking code'), 'numeric'],
        'phone' => [__('Phone'), '01XXXXXXXXX', 'tel'],
        'name' => [__('Name'), __('Customer name'), 'text'],
    ];
    $seePhone = auth()->user()->canSeeField('customer_contact');
@endphp

<x-layouts.app :heading="__('Search')">
    <div class="mx-auto max-w-4xl">
        {{-- Search: pick where to look, type, Enter. Recent searches come back with one tap. --}}
        <form method="GET" action="{{ route('orders.search') }}" x-ref="f"
            x-data="{
                by: @js($by), q: @js($q), modes: @js($modes), recent: [],
                init() {
                    try { this.recent = JSON.parse(localStorage.getItem('iqs-order-search') || '[]') } catch (e) {}
                    if (this.q) { this.recent = [{ q: this.q, by: this.by }, ...this.recent.filter(r => !(r.q === this.q && r.by === this.by))].slice(0, 6);
                        try { localStorage.setItem('iqs-order-search', JSON.stringify(this.recent)) } catch (e) {} }
                },
                pick(k) { this.by = k; this.$nextTick(() => { this.q.trim() ? this.$refs.f.submit() : this.$refs.q.focus() }) },
                again(r) { this.by = r.by; this.q = r.q; this.$nextTick(() => this.$refs.f.submit()) },
            }"
            class="mb-5 rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <input type="hidden" name="by" :value="by">
            <div class="mb-3 grid grid-cols-4 gap-1 rounded-xl bg-gray-100 p-1 text-sm font-medium">
                @foreach ($modes as $key => [$label])
                    <button type="button" @click="pick(@js($key))" class="rounded-lg py-2 transition"
                        :class="by === @js($key) ? 'bg-white text-primary shadow-sm' : 'text-gray-600 hover:text-gray-900'">{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex gap-2">
                <div class="relative min-w-0 flex-1">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                    <input type="search" name="q" x-ref="q" x-model="q" autofocus autocomplete="off" :placeholder="modes[by][1]" :inputmode="modes[by][2]"
                        class="w-full rounded-xl border border-gray-300 py-3.5 pl-11 pr-4 text-base focus:border-primary focus:outline-none">
                </div>
                <x-button class="shrink-0 px-5">{{ __('Search') }}</x-button>
            </div>
            <div x-show="recent.length" x-cloak class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                <span class="text-gray-400">{{ __('Recent') }}</span>
                <template x-for="r in recent" :key="r.by + r.q">
                    <button type="button" @click="again(r)" class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-gray-700 hover:border-primary hover:text-primary">
                        <span x-text="r.q"></span><span x-show="r.by !== 'all'" class="text-gray-400" x-text="' · ' + modes[r.by][0]"></span>
                    </button>
                </template>
                <button type="button" @click="recent = []; try { localStorage.removeItem('iqs-order-search') } catch (e) {}" class="ml-1 text-gray-400 hover:text-red-600">{{ __('Clear') }}</button>
            </div>
        </form>

        @if ($q === '')
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                <svg class="mx-auto mb-3 h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                <p class="text-sm font-medium text-gray-700">{{ __('Find any order') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('Order number (IQ10313 or 10313), phone, Steadfast CN or the customer name.') }}</p>
            </div>
        @elseif ($tooShort)
            <x-empty-state :message="__('Type at least 3 characters.')" />
        @elseif ($results->isEmpty() && $past->isEmpty() && $customers->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
                <p class="text-sm font-medium text-gray-700">{{ __('No order found for :q', ['q' => $q]) }}</p>
                @if ($by !== 'all')<p class="mt-1 text-sm text-gray-500">{{ __('Searched in :m only.', ['m' => $modes[$by][0]]) }} <a href="{{ route('orders.search', ['q' => $q]) }}" class="text-primary hover:underline">{{ __('Search in All') }}</a></p>@endif
            </div>
        @else
            {{-- The customer(s) the search is about. --}}
            @foreach ($customers as $c)
                @php $done = $c->delivered_count + $c->returned_count; @endphp
                <a href="{{ route('customers.show', $c) }}" class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary/30 bg-primary-soft p-4 hover:border-primary">
                    <span class="min-w-0">
                        <span class="block text-xs font-medium uppercase tracking-wide text-primary">{{ __('Customer') }}</span>
                        <span class="block truncate text-base font-semibold text-gray-900">{{ $c->name }}</span>
                        <span class="block font-mono text-sm text-gray-600">{{ \App\Support\Permissions\Mask::value($c->primary_phone, 'customer_contact') }}</span>
                    </span>
                    <span class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <span><span class="font-semibold tabular-nums text-gray-900">{{ $c->orders_count }}</span> <span class="text-gray-600">{{ __('orders') }}</span></span>
                        <span><span class="font-semibold tabular-nums text-green-700">{{ $c->delivered_count }}</span> <span class="text-gray-600">{{ __('delivered') }}</span></span>
                        <span><span class="font-semibold tabular-nums {{ $c->returned_count ? 'text-red-700' : 'text-gray-900' }}">{{ $c->returned_count }}</span> <span class="text-gray-600">{{ __('returned') }}</span></span>
                        @if ($done)<span class="text-gray-600">{{ __(':p% delivered', ['p' => round($c->delivered_count * 100 / $done)]) }}</span>@endif
                        @if ($c->risk_level && $c->risk_level !== 'normal')<x-badge color="red">{{ \App\Support\Labels::risk($c->risk_level) }}</x-badge>@endif
                        <span class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white">{{ __('Open customer') }}</span>
                    </span>
                </a>
            @endforeach
            @foreach ($past as $p)
                <div class="mb-3 rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono text-base font-semibold text-gray-900">CN {{ $p->cn }}</p>
                            <p class="text-base text-gray-800">{{ $p->name }} <span class="font-mono text-sm text-gray-500">{{ \App\Support\Permissions\Mask::value($p->primary_phone, 'customer_contact') }}</span></p>
                        </div>
                        <p class="text-lg font-semibold tabular-nums text-gray-900">৳{{ number_format((float) $p->amount) }}</p>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ __('Imported: :s', ['s' => $p->source]) }} · {{ __(\App\Services\Customers\HistoryImport::STATUSES[$p->status] ?? $p->status) }} · {{ \Illuminate\Support\Carbon::parse($p->ordered_at)->format('d M Y') }}@if ($p->items) · {{ $p->items }}@endif</p>
                    @can('customers.view')
                        <a href="{{ route('customers.show', $p->customer_id) }}" class="mt-3 inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:border-primary hover:text-primary">{{ __('Open customer') }}</a>
                    @endcan
                </div>
            @endforeach
            @if ($results->isNotEmpty())
            <p class="mb-2 px-1 text-xs text-gray-500">{{ trans_choice(':count order found|:count orders found', $results->count(), ['count' => $results->count()]) }}@if ($results->count() === 25) · {{ __('showing the newest 25') }}@endif</p>
            <div class="space-y-3">
                @foreach ($results as $o)
                    @php
                        $canOpen = isset($openable[$o->id]);
                        $s = $statuses[$o->status_id] ?? null;
                        $cn = $cns[$o->id] ?? null;
                    @endphp
                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white" x-data="{ copied: null, copy(t, k) { navigator.clipboard?.writeText(t); this.copied = k; setTimeout(() => this.copied = null, 1200) } }">
                        <div class="flex">
                            <div class="w-1.5 shrink-0" style="background: {{ $s['color'] ?? '#9ca3af' }}"></div>
                            <div class="min-w-0 flex-1 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2">
                                            @if ($canOpen)
                                                <a href="{{ route('orders.show', $o) }}" class="font-mono text-lg font-semibold text-gray-900 hover:text-primary">{{ $o->order_no }}</a>
                                            @else
                                                <span class="font-mono text-lg font-semibold text-gray-900">{{ $o->order_no }}</span>
                                            @endif
                                            <x-order-status :order="$o" :statuses="$statuses" />
                                        </p>
                                        <p class="mt-0.5 text-base text-gray-800">{{ $o->ship_name }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-lg font-semibold tabular-nums text-gray-900">৳{{ number_format((float) $o->grand_total) }}</p>
                                        <p class="text-xs text-gray-500">{{ __('COD') }} ৳{{ number_format((float) $o->cod_amount) }}</p>
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                    <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
                                        <span class="text-xs text-gray-500">{{ __('Phone') }}</span>
                                        <span class="font-mono text-gray-900">{{ Mask::value($o->ship_phone, 'customer_contact') }}</span>
                                        @if ($seePhone)
                                            <span class="ml-auto flex items-center gap-3 text-xs font-medium">
                                                <a href="tel:{{ $o->ship_phone }}" class="text-primary hover:underline">{{ __('Call') }}</a>
                                                <button type="button" @click="copy(@js($o->ship_phone), 'p')" class="text-primary hover:underline" x-text="copied === 'p' ? @js(__('Copied')) : @js(__('Copy'))"></button>
                                            </span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
                                        <span class="text-xs text-gray-500">CN</span>
                                        <span class="font-mono text-gray-900">{{ $cn ?? '-' }}</span>
                                        @if ($cn)
                                            <button type="button" @click="copy(@js($cn), 'c')" class="ml-auto text-xs font-medium text-primary hover:underline" x-text="copied === 'c' ? @js(__('Copied')) : @js(__('Copy'))"></button>
                                        @endif
                                    </div>
                                </div>

                                <p class="mt-3 text-sm text-gray-700">{{ $o->items->map(fn ($i) => $i->name_snapshot.' × '.\App\Support\Units::qty($i->qty, $i->unit))->join(', ') }}</p>
                                <p class="mt-2 text-xs text-gray-500">
                                    {{ collect([$o->ship_thana, $o->ship_district])->filter()->join(', ') ?: '-' }}
                                    · {{ \App\Models\Order::channelLabel($o->channel) }}
                                    · {{ $o->created_at->format('d M Y, g:i A') }}
                                    · {{ __('With') }} {{ $o->moderator?->name ?? __('nobody') }}
                                </p>
                                @can('customers.view')
                                    <a href="{{ route('customers.show', $o->customer_id) }}" class="mt-3 inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:border-primary hover:text-primary">{{ __('Open customer') }}</a>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @endif
        @endif
    </div>
</x-layouts.app>
