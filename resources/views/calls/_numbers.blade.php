{{-- Every number of the list (managers): search, filters, sort, pages; a row opens the details popup. --}}
@php
    use App\Http\Controllers\CallListController;
    use App\Services\Calls\CallListService;
    use App\Support\Permissions\Mask;
    $f = $filters;
    $range = array_filter(['from' => $f['from'], 'to' => $f['to']]);
    $here = route('calls.lists.show', [$list->id] + $range);
    $filtered = $f['q'] !== '' || $f['state'] !== '' || $f['outcome'] !== '' || $f['caller'] !== '' || $f['mood'] !== '' || $f['flag'] !== '' || $f['sort'] !== 'recent';
    // What is on the screen goes into the download: the same filters and dates.
    $now = array_filter($f, fn ($v, $k) => $v !== '' && ! ($k === 'sort' && $v === 'recent'), ARRAY_FILTER_USE_BOTH);
    // Links that set the filters say clear=1, so what they set is remembered exactly (see remembered()).
    $keep = $range + ($f['per'] !== 25 ? ['per' => $f['per']] : []);
    $chip = fn (array $q) => route('calls.lists.show', [$list->id] + $keep + $q + ['clear' => 1]).'#numbers';
    $submit = '$nextTick(() => $el.closest(\'form\').submit())';
    $perOptions = collect(\App\Http\Controllers\CallListController::PER_PAGE)->mapWithKeys(fn ($n) => [$n => __(':n a page', ['n' => $n])])->all();
    $chips = [
        [__('All'), [], ! $filtered],
        [__('Not called'), ['state' => 'not_called'], $f['state'] === 'not_called'],
        [__('Unhappy'), ['mood' => 'unhappy'], $f['mood'] === 'unhappy'],
        [__('Ordered'), ['flag' => 'ordered'], $f['flag'] === 'ordered'],
        [__('Call later'), ['state' => 'later'], $f['state'] === 'later'],
        [__('Wrong number'), ['outcome' => 'wrong'], $f['outcome'] === 'wrong'],
    ];
    $stateBadge = fn ($n) => match (true) {
        $n->state === 'in_hand' => ['blue', __('With :n now', ['n' => $n->by ?? '-'])],
        $n->state === 'later' => ['amber', __('Again :d', ['d' => $n->next_at ? \Illuminate\Support\Carbon::parse($n->next_at)->format('d M, g:i A') : '-'])],
        $n->state === 'done' => ['green', __('Done')],
        default => ['gray', __('Not called yet')],
    };
    $mood = fn ($s) => $s === null ? null : ($s < 50 ? 'red' : ($s == 50 ? 'amber' : 'green'));
@endphp

<div id="numbers" class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-1.5">
            @foreach ($chips as [$label, $q, $on])
                <a href="{{ $chip($q) }}" class="rounded-full border px-3 py-1 text-xs font-medium {{ $on ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-700 hover:border-primary' }}">{{ $label }}</a>
            @endforeach
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500">{{ trans_choice(':count number|:count numbers', $numbers->total(), ['count' => number_format($numbers->total())]) }}</span>
            <x-export-buttons :print="route('calls.lists.print', [$list->id] + $now)" :excel="route('calls.lists.excel', [$list->id] + $now)" />
        </div>
    </div>

    <form method="GET" action="{{ $here }}#numbers" class="space-y-2">
        <input type="hidden" name="clear" value="1">
        @foreach ($range as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <div class="flex gap-2">
            <input type="search" name="q" value="{{ $f['q'] }}" placeholder="{{ __('Name or phone') }}" autocomplete="off"
                class="min-w-0 flex-1 h-10 rounded-lg border border-gray-300 px-3 text-base sm:text-sm focus:border-primary focus:outline-none">
            <x-button>{{ __('Search') }}</x-button>
            @if ($filtered)<x-button variant="secondary" :href="$chip([])">{{ __('Clear') }}</x-button>@endif
        </div>
        <div class="grid grid-cols-2 gap-2 lg:grid-cols-7">
            <x-simple-select name="state" :options="['' => __('Any state')] + array_map('__', CallListController::STATES)" :value="$f['state']" full-width size="sm" x-on:select-change="{{ $submit }}" />
            <x-simple-select name="outcome" :options="['' => __('Any result')] + array_map('__', CallListService::OUTCOMES) + ['not_reached' => __('No answer or off')]" :value="$f['outcome']" full-width size="sm" x-on:select-change="{{ $submit }}" />
            <x-simple-select name="caller" :options="['' => __('Any caller')] + $calledBy" :value="$f['caller']" full-width size="sm" x-on:select-change="{{ $submit }}" />
            <x-simple-select name="mood" :options="['' => __('Any satisfaction'), 'unhappy' => __('Unhappy'), 'okay' => __('Okay'), 'happy' => __('Happy')]" :value="$f['mood']" full-width size="sm" x-on:select-change="{{ $submit }}" />
            <x-simple-select name="flag" :options="['' => __('Everything'), 'ordered' => __('Ordered on the call'), 'earlier' => __('Earlier file used')]" :value="$f['flag']" full-width size="sm" x-on:select-change="{{ $submit }}" />
            <x-simple-select name="sort" :options="array_map('__', CallListController::SORTS)" :value="$f['sort']" full-width size="sm" x-on:select-change="{{ $submit }}" />
            <x-simple-select name="per" :options="$perOptions" :value="$f['per']" full-width size="sm" class="col-span-2 lg:col-span-1" x-on:select-change="{{ $submit }}" />
        </div>
    </form>

    @if ($numbers->isEmpty())
        <p class="mt-4 rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">{{ __('No number matches.') }}</p>
    @else
        {{-- Phone and tablet: cards. --}}
        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:hidden">
            @foreach ($numbers as $n)
                @php [$color, $label] = $stateBadge($n); @endphp
                <button type="button" @click="openItem({{ $n->id }})" class="rounded-xl border border-gray-200 p-3 text-left hover:border-primary">
                    <span class="flex items-start justify-between gap-2">
                        <span class="min-w-0"><span class="block truncate font-medium text-gray-900">{{ $n->name }}</span>
                            <span class="block font-mono text-xs text-gray-500">{{ $seePhone ? $n->phone : Mask::value($n->phone, 'customer_contact') }}</span></span>
                        <x-badge :color="$color">{{ $label }}</x-badge>
                    </span>
                    <span class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600">
                        @if ($n->outcome)<span class="font-medium text-gray-800">{{ __(CallListService::OUTCOMES[$n->outcome] ?? $n->outcome) }}</span>@endif
                        @if ($n->satisfaction !== null)<x-badge :color="$mood($n->satisfaction)">{{ $n->satisfaction }}%</x-badge>@endif
                        @if ($n->order_no)<span class="text-green-700">{{ $n->order_no }} · ৳{{ number_format((float) $n->grand_total) }}</span>@endif
                        @if ($n->attempts)<span>{{ trans_choice(':count try|:count tries', $n->attempts, ['count' => $n->attempts]) }}@if ($n->by) · {{ $n->by }}@endif · {{ \Illuminate\Support\Carbon::parse($n->updated_at)->format('d M, g:i A') }}</span>@endif
                    </span>
                    @if ($n->note)<span class="mt-1 block truncate text-xs text-gray-500">{{ $n->note }}</span>@endif
                </button>
            @endforeach
        </div>

        {{-- Desktop: table; a row opens the details. --}}
        <div class="mt-3 hidden overflow-x-auto lg:block">
            <table class="w-full text-sm">
                <thead class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                    <tr class="border-b border-gray-200 [&_th]:px-3 [&_th]:py-2">
                        <th>{{ __('Customer') }}</th><th>{{ __('State') }}</th><th>{{ __('Result') }}</th><th>{{ __('Satisfied') }}</th><th>{{ __('Last call') }}</th><th>{{ __('Order') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($numbers as $n)
                        @php [$color, $label] = $stateBadge($n); @endphp
                        <tr @click="openItem({{ $n->id }})" class="cursor-pointer border-b border-gray-100 align-top hover:bg-gray-50 [&_td]:px-3 [&_td]:py-2.5">
                            <td><span class="font-medium text-gray-900">{{ $n->name }}</span><span class="block font-mono text-xs text-gray-500">{{ $seePhone ? $n->phone : Mask::value($n->phone, 'customer_contact') }}</span></td>
                            <td><x-badge :color="$color">{{ $label }}</x-badge></td>
                            <td class="max-w-[16rem]">@if ($n->outcome)<span class="text-gray-800">{{ __(CallListService::OUTCOMES[$n->outcome] ?? $n->outcome) }}</span>@else<span class="text-gray-400">-</span>@endif
                                @if ($n->note)<span class="block truncate text-xs text-gray-500" title="{{ $n->note }}">{{ $n->note }}</span>@endif</td>
                            <td>@if ($n->satisfaction !== null)<x-badge :color="$mood($n->satisfaction)">{{ $n->satisfaction }}%</x-badge>@else<span class="text-gray-400">-</span>@endif</td>
                            <td class="whitespace-nowrap text-xs text-gray-600">@if ($n->attempts){{ $n->by ?? '-' }}<span class="block text-gray-400">{{ \Illuminate\Support\Carbon::parse($n->updated_at)->format('d M, g:i A') }} · {{ trans_choice(':count try|:count tries', $n->attempts, ['count' => $n->attempts]) }}</span>@else<span class="text-gray-400">-</span>@endif</td>
                            <td class="whitespace-nowrap">@if ($n->order_no)<span class="text-green-700">{{ $n->order_no }}</span><span class="block text-xs text-gray-500">৳{{ number_format((float) $n->grand_total) }}</span>@else<span class="text-gray-400">-</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $numbers->links() }}</div>
    @endif
</div>
