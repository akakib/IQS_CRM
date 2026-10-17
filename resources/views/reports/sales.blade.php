{{-- Sales report: the three steps side by side (tap one to see the rest of the page for it), where the
     orders came from (returning or new customer, per channel), by day, top items and categories.
     Every bar and every row with › opens its orders in the popup below (sales-report.orders). --}}
@php
    use App\Models\Order;
    use App\Services\Reports\SalesReport;
    use App\Support\Units;
    $q = fn (array $more = []) => array_merge(['from' => $from, 'to' => $to, 'stage' => $stage], $more);
    $presets = [__('Today') => [today(), today()], __('7 days') => [today()->subDays(6), today()], __('This month') => [today()->startOfMonth(), today()]];
    $money = fn ($n) => '৳'.number_format((float) $n);
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
    $stageWord = mb_strtolower(__(SalesReport::STAGES[$stage]));
    $short = ['web' => 'WEB', 'messenger' => 'FB', 'whatsapp' => 'WA', 'comments' => 'FC', 'instagram' => 'IG', 'tiktok' => 'TT', 'telegram' => 'TG', 'phone' => 'PH', 'b2b' => 'B2B', 'other' => 'OT'];
    $all = ['orders' => (int) $channels->sum('orders'), 'money' => (float) $channels->sum('money'), 'ret' => (int) $channels->sum('ret'), 'retMoney' => (float) $channels->sum('ret_money')];
    $retPct = $all['orders'] ? (int) round($all['ret'] / $all['orders'] * 100) : 0;
    $items = $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->product.($p->variant !== 'Default' ? ' · '.$p->variant : ''),
        'money' => (float) $p->money, 'qty' => (float) $p->qty, 'amount' => (float) $p->grams + (float) $p->ml,
        'moneyText' => $money($p->money), 'qtyText' => $num($p->qty), 'amountText' => Units::amount((float) $p->grams, (float) $p->ml),
        'out' => $p->stock === 'out_of_stock'])->values();
    $cats = $products->groupBy(fn ($p) => $p->category ?: __('No category'))->map->sum('money')->sortDesc();
    $catTotal = max(1, $cats->sum());
    $days = \Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) + 1;
@endphp

<x-layouts.app :heading="__('Sales report')">
<div x-data="{
        pop: false, title: '', html: '', loading: false, order: null,
        metric: 'orders', itemMetric: 'money',
        bars: {{ \Illuminate\Support\Js::from($bars) }},
        items: {{ \Illuminate\Support\Js::from($items) }},
        get max() { return Math.max(1, ...this.bars.map(b => b[this.metric])) },
        get top() { const k = this.itemMetric; return [...this.items].sort((a, b) => b[k] - a[k]).slice(0, 10) },
        barText(b) { const v = b[this.metric]; return ! v ? '' : this.metric === 'money' ? (v >= 1000 ? (v / 1000).toFixed(1) + 'k' : Math.round(v)) : v },
        async show(params, title) {
            this.title = title; this.order = null; this.html = ''; this.pop = true; this.loading = true;
            try {
                const r = await fetch(@js(route('sales-report.orders', $q())) + '&' + new URLSearchParams(params), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                this.html = r.ok ? await r.text() : @js('<p class="py-6 text-center text-sm text-red-600">'.e(__('Could not load the orders. Try again.')).'</p>');
            } finally { this.loading = false }
        },
        showAll() { this.title = @js(__('All items')); this.order = null; this.html = this.$refs.allItems.innerHTML; this.pop = true },
        pick(e) { const b = e.target.closest('[data-order]'); if (b) this.order = { id: b.dataset.order, no: b.dataset.no } },
    }" @keydown.escape.window="pop = false">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($presets as $label => [$f, $t])
                @php $on = $from === $f->toDateString() && $to === $t->toDateString(); @endphp
                <a href="{{ route('sales-report.index', $q(['from' => $f->toDateString(), 'to' => $t->toDateString()])) }}" @class(['inline-flex min-h-10 items-center rounded-full border px-4 text-sm', 'border-primary bg-primary text-white' => $on, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $on])>{{ $label }}</a>
            @endforeach
            <form method="GET" x-ref="range" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="stage" value="{{ $stage }}">
                <x-date-input name="from" :value="$from" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
                <span class="text-sm text-gray-400">{{ __('to') }}</span>
                <x-date-input name="to" :value="$to" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
            </form>
        </div>
        <x-export-buttons :print="route('sales-report.print', $q())" :excel="route('sales-report.excel', $q())" size="md" />
    </div>

    {{-- The three steps side by side, against the same number of days before. Tap one: the rest of the page follows it. --}}
    <div class="grid gap-3 md:grid-cols-3">
        @foreach (SalesReport::STAGES as $key => $label)
            @php
                $s = $summary[$key];
                $p = $previous[$key]['orders'];
                $change = $p ? (int) round(($s['orders'] - $p) / $p * 100) : null;
                $extra = match ($key) {
                    'confirmed' => $s['orders'] ? __('avg :m an order', ['m' => $money($s['money'] / $s['orders'])]) : null,
                    'packed' => $summary['confirmed']['orders'] ? __(':p% of confirmed', ['p' => (int) round($s['orders'] / $summary['confirmed']['orders'] * 100)]) : null,
                    default => null,
                };
            @endphp
            <a href="{{ route('sales-report.index', $q(['stage' => $key])) }}" @class(['block rounded-2xl border p-4 transition', 'border-primary bg-primary-soft ring-1 ring-primary' => $stage === $key, 'border-gray-200 bg-white hover:border-primary' => $stage !== $key])>
                <span class="flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold text-gray-600">{{ __($label) }}</span>
                    @if ($change !== null)
                        <span @class(['rounded-full px-2 py-0.5 text-xs font-bold tabular-nums', 'bg-green-100 text-green-800' => $change >= 0, 'bg-red-100 text-red-700' => $change < 0])>{{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}%</span>
                    @endif
                </span>
                <span class="mt-1 block text-3xl font-bold tabular-nums text-gray-900">{{ number_format($s['orders']) }} <span class="text-sm font-medium text-gray-500">{{ trans_choice('order|orders', $s['orders']) }}</span></span>
                <span class="block text-base font-semibold tabular-nums text-gray-800">{{ $money($s['money']) }}</span>
                <span class="mt-1 block text-sm tabular-nums text-gray-600">{{ Units::amount($s['grams'], $s['ml']) }}{{ $extra ? ' · '.$extra : '' }}</span>
                @php $since = array_filter([__(':n cancelled', ['n' => $s['cancelled']]) => $s['cancelled'], __(':n on hold', ['n' => $s['hold']]) => $s['hold'], __(':n returned', ['n' => $s['returned']]) => $s['returned']]); @endphp
                @if ($since)<span class="mt-2 block text-xs font-medium text-amber-700">{{ __('Since then: :list', ['list' => implode(' · ', array_keys($since))]) }}</span>@endif
            </a>
        @endforeach
    </div>
    <p class="mb-5 mt-2 text-xs text-gray-500">{{ $days === 1
        ? __('▲▼ against the day before (:a).', ['a' => \Illuminate\Support\Carbon::parse($before[0])->format('d M')])
        : __('▲▼ against the :n days before (:a to :b).', ['n' => $days, 'a' => \Illuminate\Support\Carbon::parse($before[0])->format('d M'), 'b' => \Illuminate\Support\Carbon::parse($before[1])->format('d M')]) }}</p>

    {{-- Where the orders came from: returning or new customer, and per channel. --}}
    <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ __('Where the orders came from') }}</h2>
            <span class="text-xs text-gray-500">{{ __(':s orders in these dates', ['s' => __(SalesReport::STAGES[$stage])]) }}</span>
        </div>
        @if (! $all['orders'])
            <p class="py-4 text-center text-sm text-gray-500">{{ __('Nothing :s in these dates.', ['s' => $stageWord]) }}</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border-l-4 border-primary bg-gray-50 p-3">
                    <p class="text-sm text-gray-600">{{ __('Returning customers (bought before)') }}</p>
                    <p class="text-2xl font-bold tabular-nums text-gray-900">{{ $retPct }}%</p>
                    <p class="text-sm tabular-nums text-gray-600">{{ trans_choice(':n order|:n orders', $all['ret'], ['n' => $all['ret']]) }} · {{ $money($all['retMoney']) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-primary/30 bg-gray-50 p-3">
                    <p class="text-sm text-gray-600">{{ __('New customers (first order)') }}</p>
                    <p class="text-2xl font-bold tabular-nums text-gray-900">{{ 100 - $retPct }}%</p>
                    <p class="text-sm tabular-nums text-gray-600">{{ trans_choice(':n order|:n orders', $all['orders'] - $all['ret'], ['n' => $all['orders'] - $all['ret']]) }} · {{ $money($all['money'] - $all['retMoney']) }}</p>
                </div>
            </div>
            <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-gray-100"><span class="bg-primary" style="width: {{ $retPct }}%"></span><span class="bg-primary/30" style="width: {{ 100 - $retPct }}%"></span></div>
            <p class="mt-2 flex gap-4 text-xs text-gray-500"><span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-primary"></i>{{ __('Returning') }}</span><span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-primary/30"></i>{{ __('New') }}</span></p>

            <div class="mt-2">
                @foreach ($channels as $c)
                    @php $p = (int) round($c->ret / max(1, $c->orders) * 100); $label = Order::channelLabel($c->channel); @endphp
                    <button type="button" @click="show({ channel: @js($c->channel) }, @js($label))"
                        class="grid w-full grid-cols-[2.25rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 rounded-xl px-1.5 py-2.5 text-left hover:bg-gray-50 focus-visible:bg-gray-50">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-[11px] font-bold text-gray-500">{{ $short[$c->channel] ?? strtoupper(substr((string) $c->channel, 0, 2)) }}</span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-gray-900">{{ $label }}</span>
                            <span class="block text-xs text-gray-500">{{ __(':a% of orders · :b% returning', ['a' => (int) round($c->orders / $all['orders'] * 100), 'b' => $p]) }}</span>
                        </span>
                        <span class="text-right tabular-nums">
                            <span class="block text-sm text-gray-900">{{ trans_choice(':n order|:n orders', $c->orders, ['n' => $c->orders]) }} <span class="font-semibold text-primary">›</span></span>
                            <span class="block text-xs text-gray-500">{{ $money($c->money) }}</span>
                        </span>
                        <span class="col-start-2 col-end-4 flex h-2 overflow-hidden rounded-full bg-gray-100"><span class="bg-primary" style="width: {{ $p }}%"></span><span class="bg-primary/30" style="width: {{ 100 - $p }}%"></span></span>
                    </button>
                @endforeach
            </div>
        @endif
    </section>

    {{-- By day (by month when the dates run past two months). Tap a bar for its orders. --}}
    <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-4">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ __(':s by :p', ['s' => __(SalesReport::STAGES[$stage]), 'p' => $by === 'month' ? __('month') : __('day')]) }}</h2>
            <div class="grid grid-cols-3 gap-1 rounded-xl bg-gray-100 p-1">
                @foreach (['orders' => __('Orders'), 'money' => '৳', 'kg' => __('kg')] as $k => $l)
                    <button type="button" @click="metric = @js($k)" :class="metric === @js($k) ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" class="min-h-8 rounded-lg px-3 text-xs font-semibold">{{ $l }}</button>
                @endforeach
            </div>
        </div>
        <div class="flex h-52 items-end gap-1.5 overflow-x-auto border-b border-gray-200 pb-6 pt-2">
            <template x-for="b in bars" :key="b.key">
                <button type="button" @click="show({ day: b.key }, b.title)" :aria-label="b.title" class="group relative flex h-full min-w-[1.4rem] flex-1 flex-col items-center justify-end">
                    <span class="mb-0.5 text-[10px] tabular-nums text-gray-500" x-text="barText(b)"></span>
                    <span class="w-full max-w-9 rounded-t-md group-hover:ring-2 group-hover:ring-primary group-hover:ring-offset-1" :class="b[metric] ? 'bg-primary' : 'bg-gray-200'" :style="`height: ${Math.max(2, b[metric] / max * 150)}px`"></span>
                    <span class="absolute -bottom-5 text-[10px] text-gray-500" x-text="b.label"></span>
                </button>
            </template>
        </div>
        <p class="mt-2 text-xs text-gray-500">{{ __('Tap a bar for its orders.') }}</p>
    </section>

    <div class="mb-5 grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        {{-- Top items: tap one for the orders that had it; out of stock = a top seller you cannot sell now. --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900">{{ __('Top items') }}</h2>
                <div class="grid grid-cols-3 gap-1 rounded-xl bg-gray-100 p-1">
                    @foreach (['money' => '৳', 'amount' => __('kg'), 'qty' => __('Qty')] as $k => $l)
                        <button type="button" @click="itemMetric = @js($k)" :class="itemMetric === @js($k) ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" class="min-h-8 rounded-lg px-3 text-xs font-semibold">{{ $l }}</button>
                    @endforeach
                </div>
            </div>
            @if ($items->isEmpty())
                <p class="py-4 text-center text-sm text-gray-500">{{ __('Nothing :s in these dates.', ['s' => $stageWord]) }}</p>
            @else
                <template x-for="it in top" :key="it.id">
                    <button type="button" @click="show({ item: it.id }, it.name)" class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 rounded-xl px-1.5 py-2 text-left hover:bg-gray-50">
                        <span class="min-w-0 truncate text-sm font-medium text-gray-900"><span x-text="it.name"></span><span x-show="it.out" class="ml-1.5 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold text-red-700">{{ __('Out of stock') }}</span></span>
                        <span class="text-sm font-semibold tabular-nums text-gray-900"><span x-text="itemMetric === 'money' ? it.moneyText : itemMetric === 'amount' ? it.amountText : it.qtyText"></span> <span class="text-primary">›</span></span>
                        <span class="col-span-2 h-2 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full bg-primary" :style="`width: ${top[0][itemMetric] ? it[itemMetric] / top[0][itemMetric] * 100 : 0}%`"></span></span>
                    </button>
                </template>
                <button type="button" @click="showAll()" class="mt-2 inline-flex min-h-9 items-center rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('All items (:n)', ['n' => $items->count()]) }} ›</button>
            @endif
        </section>

        {{-- Categories: the share of the money. --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4">
            <h2 class="mb-2 text-base font-semibold text-gray-900">{{ __('By category') }}</h2>
            @forelse ($cats as $name => $v)
                <div class="grid grid-cols-[7.5rem_minmax(0,1fr)_2.75rem] items-center gap-3 py-1.5 text-sm">
                    <span class="truncate text-gray-800">{{ $name }}</span>
                    <span class="h-2 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full bg-primary/40" style="width: {{ $v / $catTotal * 100 }}%"></span></span>
                    <span class="text-right font-semibold tabular-nums text-gray-600">{{ (int) round($v / $catTotal * 100) }}%</span>
                </div>
            @empty
                <p class="py-4 text-center text-sm text-gray-500">{{ __('Nothing :s in these dates.', ['s' => $stageWord]) }}</p>
            @endforelse
            @if ($cats->isNotEmpty())<p class="mt-2 text-xs text-gray-500">{{ __(':m in all, from each item\'s category.', ['m' => $money($cats->sum())]) }}</p>@endif
        </section>
    </div>

    {{-- Every item of this step, shown in the popup (All items). --}}
    <template x-ref="allItems">
        <ul class="divide-y divide-gray-100">
            @foreach ($products as $p)
                <li class="flex items-start justify-between gap-3 py-2.5 text-sm">
                    <span class="min-w-0">
                        <span class="block text-gray-900">{{ $p->product }}{{ $p->variant !== 'Default' ? ' · '.$p->variant : '' }}</span>
                        <span class="block text-xs text-gray-500">{{ __('Qty :n', ['n' => $num($p->qty)]) }} · {{ Units::amount((float) $p->grams, (float) $p->ml) }}</span>
                    </span>
                    <span class="shrink-0 font-semibold tabular-nums text-gray-900">{{ $money($p->money) }}</span>
                </li>
            @endforeach
        </ul>
    </template>

    {{-- The popup: a list of orders (or all items); tap an order number to open the order inside it. --}}
    <div x-show="pop" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/40" @click="pop = false"></div>
        <div class="relative flex max-h-[88dvh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" :class="order ? 'h-[88dvh]' : ''">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                <div class="flex min-w-0 items-center gap-2">
                    <button type="button" x-show="order" @click="order = null" class="text-sm font-medium text-primary">‹ {{ __('List') }}</button>
                    <h3 class="truncate text-sm font-semibold text-gray-900" x-text="order ? order.no : title"></h3>
                </div>
                <button type="button" @click="pop = false" class="text-xl leading-none text-gray-400 hover:text-gray-600" aria-label="{{ __('Close') }}">&times;</button>
            </div>
            <div x-show="! order" class="min-h-0 overflow-y-auto px-5 py-3" @click="pick($event)">
                <p x-show="loading" class="py-6 text-center text-sm text-gray-500">{{ __('Loading...') }}</p>
                <div x-html="html"></div>
            </div>
            <template x-if="order">
                <iframe :src="@js(route('desk.index', ['embed' => 1])) + '&order=' + order.id" title="{{ __('Order') }}" class="min-h-0 w-full flex-1 border-0 bg-gray-50"></iframe>
            </template>
        </div>
    </div>
</div>
</x-layouts.app>
