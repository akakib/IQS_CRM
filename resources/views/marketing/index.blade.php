@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    $tk = fn ($v) => $v === null ? '-' : '৳'.number_format((float) $v);
    $usd = fn ($v) => '$'.number_format((float) $v, 2);
    $x = fn ($v) => $v === null ? '-' : number_format($v, 2).'x';
    $t = $report['totals'];
    $accountOptions = $accounts->where('is_active', true)->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.ucfirst($a->platform).')'])->all();
@endphp

<x-layouts.app :heading="__('Ad spend & ROAS')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Real taka cost of ad spend (dollar lots used oldest first) against the orders placed the same day. Delivered ROAS is the one that pays the bills.') }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
        <form method="GET" action="{{ route('marketing.index') }}" class="flex flex-wrap items-center gap-2">
            <x-date-range :from="$from" :to="$to" />
            <x-button size="sm">{{ __('Show') }}</x-button>
            @if (request()->hasAny(['from', 'to']))
                <a href="{{ route('marketing.index') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
        </form>
        @can('marketing.create')
            <form method="POST" action="{{ route('marketing.pull') }}" class="md:ml-auto">@csrf<x-button size="sm" variant="secondary">{{ __('Pull spend now') }}</x-button></form>
        @endcan
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <x-stat-tile :label="__('Spend')" :value="$usd($t['usd'])" :hint="$tk($t['bdt'])" />
        <x-stat-tile :label="__('Delivered ROAS')" :value="$x($t['delivered_roas'])" :trend="($t['delivered_roas'] ?? 0) >= 1 ? 'up' : 'down'" />
        <x-stat-tile :label="__('Confirmed ROAS')" :value="$x($t['confirmed_roas'])" />
        <x-stat-tile :label="__('Meta ROAS (reported)')" :value="$x($t['meta_roas'])" />
        <x-stat-tile :label="__('MER (blended)')" :value="$x($t['mer'])" />
        <x-stat-tile :label="__('Cost per order')" :value="$tk($t['cost_per_order'])" :hint="__('Per message :m', ['m' => $tk($t['cost_per_message'])])" />
    </div>

    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 text-sm">
        <p class="font-medium text-gray-800">{{ __('Dollar balance') }}</p>
        <p class="mt-1 text-gray-600">
            {{ __('Bought :b − spent :s = :r left', ['b' => $usd($balance['bought']), 's' => $usd($balance['spent']), 'r' => $usd($balance['bought'] - $balance['spent'])]) }}
            · {{ __('in open lots :o', ['o' => $usd($balance['remaining'])]) }}
            @if ($balance['unfunded'] > 0)
                <span class="ml-1 font-medium text-red-700">{{ __(':u of spend has no lot: add the dollars you bought.', ['u' => $usd($balance['unfunded'])]) }}</span>
            @endif
        </p>
    </div>

    @if ($report['days'] === [])
        <div class="mb-6 rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No spend or orders in this period.') }}</div>
    @else
        <x-list.table class="mb-6">
            <x-slot:head>
                <th>{{ __('Day') }}</th><th class="text-right">{{ __('Spend') }}</th><th class="text-right">{{ __('Taka cost') }}</th>
                <th class="text-right">{{ __('Messages') }}</th><th class="text-right">{{ __('Orders (web / chat / other)') }}</th>
                <th class="text-right">{{ __('Delivered revenue') }}</th><th class="text-right">{{ __('Meta / confirmed / delivered ROAS') }}</th>
                <th class="text-right">{{ __('MER') }}</th><th class="text-right">{{ __('Per order') }}</th>
            </x-slot:head>
            @foreach ($report['days'] as $d)
                <tr class="border-t border-gray-100 [&_td]:px-4 [&_td]:py-3">
                    <td class="font-medium text-gray-800">{{ \Illuminate\Support\Carbon::parse($d['day'])->format('D d M') }}</td>
                    <td class="text-right tabular-nums">{{ $usd($d['usd']) }}@if ($d['unfunded'] > 0)<span class="block text-[11px] text-red-600">{{ __(':u no lot', ['u' => $usd($d['unfunded'])]) }}</span>@endif</td>
                    <td class="text-right tabular-nums">{{ $tk($d['bdt']) }}</td>
                    <td class="text-right tabular-nums">{{ (int) $d['messages'] }}</td>
                    <td class="text-right tabular-nums">{{ (int) $d['orders'] }} <span class="text-xs text-gray-400">({{ (int) $d['web'] }} / {{ (int) $d['messenger'] }} / {{ (int) $d['other'] }})</span></td>
                    <td class="text-right tabular-nums">{{ $tk($d['delivered']) }}</td>
                    <td class="text-right tabular-nums">{{ $x($d['meta_roas']) }} / {{ $x($d['confirmed_roas']) }} / <b>{{ $x($d['delivered_roas']) }}</b></td>
                    <td class="text-right tabular-nums">{{ $x($d['mer']) }}</td>
                    <td class="text-right tabular-nums">{{ $tk($d['cost_per_order']) }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards class="mb-6">
            @foreach ($report['days'] as $d)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-gray-800">{{ \Illuminate\Support\Carbon::parse($d['day'])->format('D d M') }}</p>
                        <p class="text-sm font-semibold tabular-nums text-green-900">{{ $x($d['delivered_roas']) }}</p>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $usd($d['usd']) }} = {{ $tk($d['bdt']) }} · {{ __(':n orders', ['n' => (int) $d['orders']]) }} · {{ __(':m messages', ['m' => (int) $d['messages']]) }}</p>
                    <p class="text-xs text-gray-500">{{ __('Meta :a · Confirmed :b · MER :c', ['a' => $x($d['meta_roas']), 'b' => $x($d['confirmed_roas']), 'c' => $x($d['mer'])]) }}</p>
                </div>
            @endforeach
        </x-list.cards>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card :title="__('Ad accounts')">
            <div class="space-y-2">
                @forelse ($accounts as $a)
                    <div @class(['flex items-center justify-between gap-2 text-sm', 'opacity-50' => ! $a->is_active])>
                        <span>{{ $a->name }} <span class="text-xs text-gray-400">{{ ucfirst($a->platform) }} · {{ $a->external_id ?: __('no id') }} · {{ $a->timezone }}</span></span>
                        @can('marketing.create')
                            <form method="POST" action="{{ route('marketing.accounts.toggle', $a->id) }}">@csrf<button class="text-xs text-green-900 hover:underline">{{ $a->is_active ? __('Off') : __('On') }}</button></form>
                        @endcan
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No ad account yet.') }}</p>
                @endforelse
            </div>
            @can('marketing.create')
                <form method="POST" action="{{ route('marketing.accounts.store') }}" class="mt-4 space-y-2 border-t border-gray-100 pt-4">
                    @csrf
                    <x-simple-select name="platform" :options="['meta' => 'Meta', 'google' => 'Google']" value="meta" full-width class="w-full" />
                    <input name="name" required maxlength="120" placeholder="{{ __('Account name') }}" class="{{ $input }}">
                    <input name="external_id" maxlength="60" placeholder="{{ __('Account ID, e.g. act_123') }}" class="{{ $input }}">
                    <input name="timezone" value="Asia/Dhaka" required class="{{ $input }}" aria-label="{{ __('Timezone') }}">
                    <x-button size="sm" class="w-full">{{ __('Add account') }}</x-button>
                </form>
            @endcan
        </x-card>

        @can('marketing.create')
            <x-card :title="__('Add a day by hand')" :subtitle="__('Overwrites that account and day.')">
                <form method="POST" action="{{ route('marketing.spend.store') }}" class="space-y-2">
                    @csrf
                    <x-simple-select name="ad_account_id" :options="$accountOptions" :value="array_key_first($accountOptions)" full-width class="w-full" />
                    <x-date-input name="spend_date" :value="today()->toDateString()" :max="today()->toDateString()" :clearable="false" full-width />
                    <input type="number" name="spend_usd" step="0.01" min="0" required placeholder="{{ __('Spend (USD)') }}" class="{{ $input }}">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="messages" min="0" placeholder="{{ __('Messages') }}" class="{{ $input }}">
                        <input type="number" name="purchases" min="0" placeholder="{{ __('Purchases') }}" class="{{ $input }}">
                    </div>
                    <input type="number" name="reported_value" step="0.01" min="0" placeholder="{{ __('Purchase value reported (৳)') }}" class="{{ $input }}">
                    <x-button size="sm" class="w-full">{{ __('Save day') }}</x-button>
                </form>
            </x-card>

            <x-card :title="__('Import CSV')" :subtitle="__('A daily report from Google Ads or Meta. Needs Date (or Day) and Spend (or Cost) columns.')">
                <form method="POST" action="{{ route('marketing.import') }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf
                    <x-simple-select name="ad_account_id" :options="$accountOptions" :value="array_key_first($accountOptions)" full-width class="w-full" />
                    <input type="file" name="file" accept=".csv,text/csv" required class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm">
                    <x-button size="sm" class="w-full">{{ __('Import') }}</x-button>
                </form>
            </x-card>
        @endcan
    </div>
</x-layouts.app>
