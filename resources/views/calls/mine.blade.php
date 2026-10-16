@php
    use App\Http\Controllers\CallListController;
    use App\Services\Calls\CallListService;
    use App\Support\Permissions\Mask;
    $f = $filters;
    $here = route('calls.mine');
    $filtered = $f['q'] !== '' || $f['day'] !== 'all' || $f['list'] !== '' || $f['result'] !== '';
    $submit = '$nextTick(() => $el.closest(\'form\').submit())';
    $mood = fn ($s) => $s < 50 ? 'red' : ($s == 50 ? 'amber' : 'green');
    $phone = fn ($p) => $seePhone ? $p : Mask::value($p, 'customer_contact');
@endphp

<x-layouts.app :heading="__('My calls')">
    <div class="mx-auto max-w-5xl space-y-4" x-data="{
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

        {{-- Every call I made: search, filters, pages; a card opens the details. --}}
        <div id="history" class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-semibold text-gray-900">{{ __('My call history') }}</p>
                <p class="text-xs text-gray-500">{{ trans_choice(':count call|:count calls', $calls->total(), ['count' => number_format($calls->total())]) }}</p>
            </div>
            <form method="GET" action="{{ $here }}#history" class="space-y-2">
                <input type="hidden" name="clear" value="1">
                <div class="flex gap-2">
                    <input type="search" name="q" value="{{ $f['q'] }}" placeholder="{{ __('Name or phone') }}" autocomplete="off"
                        class="min-w-0 flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-base focus:border-primary focus:outline-none">
                    <x-button>{{ __('Search') }}</x-button>
                    @if ($filtered)<x-button variant="secondary" :href="route('calls.mine', ($f['per'] !== 25 ? ['per' => $f['per']] : []) + ['clear' => 1]).'#history'">{{ __('Clear') }}</x-button>@endif
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <x-simple-select name="day" :options="array_map('__', CallListController::DAYS)" :value="$f['day']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                    <x-simple-select name="result" :options="['' => __('Any result')] + array_map('__', CallListController::CALL_RESULTS)" :value="$f['result']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                    <x-simple-select name="list" :options="['' => __('Every list')] + $myLists" :value="$f['list']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                    <x-simple-select name="per" :options="collect(CallListController::PER_PAGE)->mapWithKeys(fn ($n) => [$n => __(':n a page', ['n' => $n])])->all()" :value="$f['per']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                </div>
            </form>

            @if ($calls->isEmpty())
                <p class="mt-4 rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">{{ $filtered ? __('No call matches.') : __('No calls yet. Press Start calling above.') }}</p>
            @else
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($calls as $c)
                        <div role="button" tabindex="0" @click="openItem({{ $c->item_id }})" @keydown.enter="openItem({{ $c->item_id }})" class="cursor-pointer rounded-xl border border-gray-200 p-3 text-left text-sm hover:border-primary">
                            <div class="flex items-start justify-between gap-2">
                                <span class="min-w-0"><span class="block truncate font-medium text-gray-900">{{ $c->name }}</span>
                                    <span class="block font-mono text-xs text-gray-500">{{ $phone($c->phone) }}</span></span>
                                <span class="shrink-0 text-right text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($c->started_at)->format('d M') }}<br>{{ \Illuminate\Support\Carbon::parse($c->started_at)->format('g:i A') }}</span>
                            </div>
                            <p class="mt-2 text-gray-700">{{ $c->note }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                @if ($c->satisfaction !== null)<x-badge :color="$mood($c->satisfaction)">{{ $c->satisfaction }}%</x-badge>@endif
                                @if ($c->order_no)<span class="font-medium text-green-700">{{ $c->order_no }}</span>@endif
                                @if ($c->duration_seconds)<span>{{ intdiv($c->duration_seconds, 60) }}:{{ str_pad((string) ($c->duration_seconds % 60), 2, '0', STR_PAD_LEFT) }}</span>@endif
                                <span class="truncate">{{ $c->list }}</span>
                            </div>
                            @if ($c->proof_path)
                                <div class="mt-2 flex flex-wrap items-center gap-2" @click.stop>
                                    <x-play-call :call="$c" :title="$c->name" :sub="\Illuminate\Support\Carbon::parse($c->started_at)->format('d M, g:i A')" />
                                    @if ($c->proof_old)<span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">{{ __('Earlier file') }}</span>@endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-3">{{ $calls->links() }}</div>
            @endif
        </div>

        @include('calls._item-popup')
    </div>
</x-layouts.app>
