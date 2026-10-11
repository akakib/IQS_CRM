@php
    $usd = fn ($v) => '$'.number_format((float) $v, 2);
    $tk = fn ($v) => '৳'.number_format((float) $v);
    $n = fn ($v) => number_format((float) $v);
    $url = fn (array $q) => route('campaigns.index', array_filter(array_merge(['from' => $from, 'to' => $to, 'account' => $account, 'level' => $level], $q)));
    $tabs = collect(\App\Services\Marketing\AdInsights::LEVELS)->map(fn ($label, $key) => [__($label), $url(['level' => $key])])->all();
    // One result kind per row reads well; several kinds summed together do not.
    $resultLabel = fn ($r) => $r->result_types > 1 ? __('mixed') : ($r->result_type ? \Illuminate\Support\Str::of($r->result_type)->afterLast('.')->replace('_', ' ')->lower() : '');
    $name = fn ($r) => match ($level) { 'ad' => $r->ad_name ?: '-', 'adset' => $r->adset_name ?: '-', default => $r->campaign_name };
    $parent = fn ($r) => match ($level) { 'ad' => $r->campaign_name.' › '.($r->adset_name ?: '-'), 'adset' => $r->campaign_name, default => null };
@endphp

<x-layouts.app :heading="__('Campaigns')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Spend and results by campaign, ad set and ad, from the Ads Manager export. Taka is the real cost of the dollars used that day when the daily spend of the account is known, else the average dollar rate.') }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
        <form method="GET" action="{{ route('campaigns.index') }}" class="flex flex-wrap items-center gap-2" x-ref="f" @select-change="setTimeout(() => $refs.f.requestSubmit(), 0)">
            <input type="hidden" name="level" value="{{ $level }}">
            <x-date-range :from="$from" :to="$to" />
            <x-simple-select name="account" :options="['' => __('All ad accounts')] + $accounts" :value="(string) ($account ?? '')" />
            <x-button size="sm">{{ __('Show') }}</x-button>
            @if (request()->hasAny(['from', 'to', 'account']))
                <a href="{{ route('campaigns.index', ['level' => $level]) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
        </form>
        @can('marketing.create')
            <x-button type="button" size="sm" class="md:ml-auto" @click="$dispatch('open-modal', 'campaign-import')">{{ __('Upload Ads Manager CSV') }}</x-button>
        @endcan
    </div>

    @can('marketing.create')
        <x-modal id="campaign-import" :title="__('Upload Ads Manager CSV')" persistent>
            <form method="POST" action="{{ route('campaigns.import') }}" enctype="multipart/form-data" id="campaign-import-form" class="space-y-3 text-sm">
                @csrf
                <ol class="list-decimal space-y-1 pl-5 text-gray-600">
                    <li>{{ __('In Ads Manager open the Ads tab and pick the dates.') }}</li>
                    <li>{{ __('Breakdown: By time, Day. Columns: keep Campaign name, Ad set name, Ad name, Amount spent, Results, Result indicator; add Account ID if you export several accounts together.') }}</li>
                    <li>{{ __('Export as CSV and upload it here. Uploading the same days again replaces them.') }}</li>
                </ol>
                <input type="file" name="file" accept=".csv,text/csv" required class="block w-full text-sm">
                <div>
                    <p class="mb-1 font-medium text-gray-700">{{ __('Ad account (only if the file has no Account ID column)') }}</p>
                    <x-simple-select name="ad_account_id" :options="['' => __('From the file')] + $accounts" value="" full-width class="w-full" />
                </div>
                <label class="flex items-start gap-2 text-gray-700"><input type="checkbox" name="fill_daily" value="1" checked class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                    <span>{{ __('Also use it as the daily spend of each account (the taka cost and ROAS follow). Leave on when the file has all the ads of those days.') }}</span></label>
                @error('file')<p class="text-red-600">{{ $message }}</p>@enderror
                @error('ad_account_id')<p class="text-red-600">{{ $message }}</p>@enderror
            </form>
            <x-slot:footer>
                <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'campaign-import')">{{ __('Cancel') }}</x-button>
                <x-button type="submit" form="campaign-import-form">{{ __('Upload') }}</x-button>
            </x-slot:footer>
        </x-modal>
        @if ($errors->hasAny(['file', 'ad_account_id']))
            <div x-data x-init="$dispatch('open-modal', 'campaign-import')"></div>
        @endif
    @endcan

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat-tile :label="__('Spend')" :value="$usd($totals['spend_usd'])" :hint="$tk($totals['spend_bdt'])" />
        <x-stat-tile :label="__('Messages')" :value="$n($totals['messages'])" :hint="$totals['messages'] ? __('৳:a each', ['a' => number_format($totals['spend_bdt'] / $totals['messages'])]) : null" />
        <x-stat-tile :label="__('Purchases (reported)')" :value="$n($totals['purchases'])" />
        <x-stat-tile :label="__('Last upload')" :value="$lastImport ? \Illuminate\Support\Carbon::parse($lastImport)->format('d M, g:i A') : '-'" />
    </div>

    <x-tabs :tabs="$tabs" :active="$level" />

    @if ($rows->isEmpty())
        <x-empty-state :message="__('Nothing in these dates. Upload an Ads Manager CSV.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __(\App\Services\Marketing\AdInsights::LEVELS[$level]) }}</th>
                <th class="text-right">{{ __('Spend') }}</th><th class="text-right">{{ __('Results') }}</th><th class="text-right">{{ __('Per result') }}</th>
                <th class="text-right">{{ __('Messages') }}</th><th class="text-right">{{ __('Purchases') }}</th><th class="text-right">{{ __('Impressions') }}</th>
                <th class="text-right">{{ __('Clicks') }}</th><th class="text-right">{{ __('Share') }}</th>
            </x-slot:head>
            @foreach ($rows as $r)
                <tr>
                    <td class="max-w-xs"><p class="truncate font-medium text-gray-800" title="{{ $name($r) }}">{{ $name($r) }}</p>@if ($parent($r))<p class="truncate text-xs text-gray-500" title="{{ $parent($r) }}">{{ $parent($r) }}</p>@endif</td>
                    <td class="text-right tabular-nums">{{ $usd($r->spend_usd) }}<span class="block text-xs text-gray-500">{{ $tk($r->spend_bdt) }}</span></td>
                    <td class="text-right tabular-nums">{{ $n($r->results) }}<span class="block text-xs text-gray-500">{{ $resultLabel($r) }}</span></td>
                    <td class="text-right tabular-nums">@if ($r->results > 0){{ $tk($r->spend_bdt / $r->results) }}<span class="block text-xs text-gray-500">{{ $usd($r->spend_usd / $r->results) }}</span>@else - @endif</td>
                    <td class="text-right tabular-nums">{{ $n($r->messages) }}</td>
                    <td class="text-right tabular-nums">{{ $n($r->purchases) }}</td>
                    <td class="text-right tabular-nums">{{ $n($r->impressions) }}</td>
                    <td class="text-right tabular-nums">{{ $n($r->clicks) }}</td>
                    <td class="text-right tabular-nums">{{ $totals['spend_usd'] > 0 ? round($r->spend_usd * 100 / $totals['spend_usd']).'%' : '-' }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards>
            @foreach ($rows as $r)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="font-medium text-gray-800">{{ $name($r) }}</p>
                    @if ($parent($r))<p class="text-xs text-gray-500">{{ $parent($r) }}</p>@endif
                    <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div><p class="text-xs text-gray-500">{{ __('Spend') }}</p><p class="font-semibold tabular-nums">{{ $usd($r->spend_usd) }}</p><p class="text-xs text-gray-500">{{ $tk($r->spend_bdt) }}</p></div>
                        <div><p class="text-xs text-gray-500">{{ __('Results') }}</p><p class="font-semibold tabular-nums">{{ $n($r->results) }}</p><p class="text-xs text-gray-500">{{ $resultLabel($r) }}</p></div>
                        <div><p class="text-xs text-gray-500">{{ __('Per result') }}</p><p class="font-semibold tabular-nums">{{ $r->results > 0 ? $tk($r->spend_bdt / $r->results) : '-' }}</p></div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ __(':m messages · :p purchases · :i impressions · :c clicks', ['m' => $n($r->messages), 'p' => $n($r->purchases), 'i' => $n($r->impressions), 'c' => $n($r->clicks)]) }}</p>
                </div>
            @endforeach
        </x-list.cards>
    @endif
</x-layouts.app>
