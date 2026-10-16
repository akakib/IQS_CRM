@php
    use App\Http\Controllers\CallListController;
    use App\Services\Calls\CallListService;
    use App\Support\Permissions\Mask;
    $f = $filters;
    $here = route('calls.history');
    $filtered = $f['q'] !== '' || $f['day'] !== 'all' || $f['list'] !== '' || $f['result'] !== '';
    $submit = '$nextTick(() => $el.closest(\'form\').submit())';
    $mood = fn ($s) => $s < 50 ? 'red' : ($s == 50 ? 'amber' : 'green');
    $phone = fn ($p) => $seePhone ? $p : Mask::value($p, 'customer_contact');
@endphp

<x-layouts.app :heading="__('My call history')">
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

        {{-- Every call I made: search, filters, pages; a card opens the details. --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <a href="{{ route('calls.mine') }}" class="text-sm font-medium text-primary hover:underline">&larr; {{ __('My calls') }}</a>
                <p class="text-xs text-gray-500">{{ trans_choice(':count call|:count calls', $calls->total(), ['count' => number_format($calls->total())]) }}</p>
            </div>
            <form method="GET" action="{{ $here }}" class="space-y-2">
                <input type="hidden" name="clear" value="1">
                <div class="flex gap-2">
                    <input type="search" name="q" value="{{ $f['q'] }}" placeholder="{{ __('Name or phone') }}" autocomplete="off"
                        class="min-w-0 flex-1 h-10 rounded-lg border border-gray-300 px-3 text-base sm:text-sm focus:border-primary focus:outline-none">
                    <x-button>{{ __('Search') }}</x-button>
                    @if ($filtered)<x-button variant="secondary" :href="route('calls.history', ($f['per'] !== 25 ? ['per' => $f['per']] : []) + ['clear' => 1])">{{ __('Clear') }}</x-button>@endif
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <x-simple-select name="day" :options="array_map('__', CallListController::DAYS)" :value="$f['day']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                    <x-simple-select name="result" :options="['' => __('Any result')] + array_map('__', CallListController::CALL_RESULTS)" :value="$f['result']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                    <x-simple-select name="list" :options="['' => __('Every list')] + $myLists" :value="$f['list']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                    <x-simple-select name="per" :options="collect(CallListController::PER_PAGE)->mapWithKeys(fn ($n) => [$n => __(':n a page', ['n' => $n])])->all()" :value="$f['per']" full-width size="sm" x-on:select-change="{{ $submit }}" />
                </div>
            </form>

            @if ($calls->isEmpty())
                <p class="mt-4 rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">{{ $filtered ? __('No call matches.') : __('No calls yet.') }}</p>
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
