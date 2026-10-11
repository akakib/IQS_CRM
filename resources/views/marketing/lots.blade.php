@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $tk = fn ($v) => '৳'.number_format((float) $v);
    $usd = fn ($v) => '$'.number_format((float) $v, 2);
    $vendorOptions = $vendors->where('is_active', true)->pluck('name', 'id')->all();
@endphp

<x-layouts.app :heading="__('USD lots')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Dollars bought for ads. Spend uses the oldest lot first, so each day gets its real taka cost.') }}</p>

    {{-- Where we stand with all vendors together. --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('We owe vendors') }}</p><p @class(['text-lg font-semibold tabular-nums', 'text-red-700' => $totals['due'] > 0])>{{ $tk($totals['due']) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Our advance with vendors') }}</p><p class="text-lg font-semibold tabular-nums text-blue-700">{{ $tk($totals['advance']) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Dollars not spent yet') }}</p><p class="text-lg font-semibold tabular-nums">{{ $usd($totals['usd_left']) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Bought this month') }}</p><p class="text-lg font-semibold tabular-nums">{{ $usd($totals['usd_month']) }}</p><p class="text-xs text-gray-500">{{ $tk($totals['bdt_month']) }}</p></div>
    </div>

    <div class="mb-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($vendors as $v)
            <div class="rounded-xl border border-gray-200 bg-white p-4" x-data="{ edit: false }">
                @can('marketing.create')<form x-show="edit" x-cloak method="POST" action="{{ route('usd-lots.vendors.update', $v->id) }}" class="mb-2 flex flex-wrap items-center gap-1.5">@csrf @method('PUT')
                    <input name="name" value="{{ $v->name }}" maxlength="120" required class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none">
                    <input name="phone" value="{{ $v->phone }}" inputmode="tel" placeholder="01XXXXXXXXX" class="w-32 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none">
                    <div class="w-full"><div x-data="{ side: @js($v->opening < 0 ? 'advance' : 'due') }" class="space-y-1.5">
                        <p class="text-xs font-medium text-gray-600">{{ __('Balance before using the app (optional)') }}</p>
                        <input type="hidden" name="opening_side" :value="side">
                        <div class="flex gap-1.5">
                            <button type="button" @click="side = 'due'" class="flex-1 rounded-full border px-2 py-1 text-xs" :class="side === 'due' ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ __('We owe them') }}</button>
                            <button type="button" @click="side = 'advance'" class="flex-1 rounded-full border px-2 py-1 text-xs" :class="side === 'advance' ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ __('They hold our advance') }}</button>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5">
                            <input type="number" name="opening_amount" min="0" step="0.01" value="{{ abs($v->opening) ?: '' }}" placeholder="{{ __('Amount (৳)') }}" class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none">
                            <x-date-input name="opening_on" :value="$v->opening_on" :max="today()->toDateString()" :placeholder="__('As of')" full-width />
                        </div>
                    </div></div>
                    <button class="text-xs font-medium text-primary hover:underline">{{ __('Save') }}</button><button type="button" @click="edit = false" class="text-xs text-gray-500">{{ __('Cancel') }}</button></form>@endcan
                <div class="flex items-center justify-between" x-show="! edit">
                    <p class="font-medium text-gray-800"><a href="{{ route('usd-lots.vendor', $v->id) }}" class="hover:text-primary hover:underline">{{ $v->name }}</a> @can('marketing.create')<button type="button" @click="edit = true" class="ml-1 text-xs font-normal text-primary hover:underline">{{ __('Edit') }}</button>@endcan</p>
                    @if ($v->due > 0)
                        <x-badge :color="$v->next_due && $v->next_due <= today()->toDateString() ? 'red' : 'amber'">{{ __('Due :a', ['a' => $tk($v->due)]) }}</x-badge>
                    @elseif ($v->advance > 0)
                        <x-badge color="blue">{{ __('Advance :a', ['a' => $tk($v->advance)]) }}</x-badge>
                    @else
                        <x-badge color="green">{{ __('Paid up') }}</x-badge>
                    @endif
                </div>
                <p class="mt-1 text-xs text-gray-500">@if ($v->opening){{ __('Opening :o', ['o' => ($v->opening < 0 ? '-' : '').$tk(abs($v->opening))]) }} · @endif{{ __('Bought :b · paid :p', ['b' => $tk($v->bought), 'p' => $tk($v->paid)]) }}@if ($v->next_due) · {{ __('due by :d', ['d' => date('d M', strtotime($v->next_due))]) }}@endif</p>
                <a href="{{ route('usd-lots.vendor', $v->id) }}" class="mt-2 inline-block text-xs font-medium text-primary hover:underline">{{ __('Full ledger') }}</a>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500 md:col-span-2 xl:col-span-3">{{ __('Add a dollar vendor to start.') }}</div>
        @endforelse
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2" x-data="tabs('lots')">
            {{-- Lots and payments, one at a time. --}}
            <x-tabs :tabs="['lots' => [__('Lots'), '#lots'], 'payments' => [__('Latest payments'), '#payments', $payments->count() ?: null]]" active="lots" />
            <section x-show="tab === 'lots'">
            <form method="GET" action="{{ route('usd-lots.index') }}" x-ref="f" @select-change="setTimeout(() => $refs.f.requestSubmit(), 0)" class="mb-3 flex flex-wrap items-center gap-2">
                <x-simple-select name="vendor" :options="['' => __('All vendors')] + $vendors->pluck('name', 'id')->all()" :value="(string) ($filters['vendor'] ?? '')" />
                <x-simple-select name="open" :options="['' => __('All lots'), '1' => __('With dollars left')]" :value="$filters['open'] ? '1' : ''" />
                <x-simple-select name="per_page" :options="[25 => __(':n / page', ['n' => 25]), 50 => __(':n / page', ['n' => 50]), 100 => __(':n / page', ['n' => 100])]" :value="$perPage" />
                @if ($filters['vendor'] || $filters['open'])
                    <a href="{{ route('usd-lots.index') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
                @endif
            </form>

            @if ($lots->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No lots yet.') }}</div>
            @else
                <x-list.table>
                    <x-slot:head>
                        <th>{{ __('Date') }}</th><th>{{ __('Vendor') }}</th><th class="text-right">{{ __('USD') }}</th><th class="text-right">{{ __('Rate') }}</th>
                        <th class="text-right">{{ __('Taka') }}</th><th class="text-right">{{ __('Left') }}</th><th></th>
                    </x-slot:head>
                    @foreach ($lots as $l)
                        <tr class="border-t border-gray-100 [&_td]:px-4 [&_td]:py-3">
                            <td>{{ date('d M Y', strtotime($l->purchased_on)) }}</td>
                            <td>{{ $l->vendor }}@if ($l->note)<span class="block text-xs text-gray-400">{{ $l->note }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $usd($l->usd) }}</td>
                            <td class="text-right tabular-nums">{{ number_format((float) $l->rate, 2) }}</td>
                            <td class="text-right tabular-nums">{{ $tk($l->bdt_total) }}</td>
                            <td @class(['text-right tabular-nums', 'text-gray-400' => (float) $l->usd_remaining <= 0, 'font-medium text-primary' => (float) $l->usd_remaining > 0])>{{ $usd($l->usd_remaining) }}</td>
                            <td class="text-right">@can('marketing.create')@if ((float) $l->usd_remaining >= (float) $l->usd)<form method="POST" action="{{ route('usd-lots.destroy', $l->id) }}" id="lot-del-{{ $l->id }}">@csrf @method('DELETE')<button type="button" @click="$dispatch('open-confirm', { id: 'lot-delete', form: 'lot-del-{{ $l->id }}', label: @js($usd($l->usd).' · '.$l->vendor) })" class="text-xs text-red-600 hover:underline">{{ __('Delete') }}</button></form>@endif @endcan</td>
                        </tr>
                    @endforeach
                </x-list.table>
                <x-list.cards>
                    @foreach ($lots as $l)
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="flex items-center justify-between">
                                <p class="font-medium text-gray-800">{{ $usd($l->usd) }} <span class="text-xs font-normal text-gray-500">@ {{ number_format((float) $l->rate, 2) }}</span></p>
                                <p class="text-sm tabular-nums text-primary">{{ __(':u left', ['u' => $usd($l->usd_remaining)]) }}</p>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ $l->vendor }} · {{ date('d M Y', strtotime($l->purchased_on)) }} · {{ $tk($l->bdt_total) }}</p>
                        </div>
                    @endforeach
                </x-list.cards>
                <div class="mt-4">{{ $lots->links() }}</div>
            @endif
            </section>

            <section x-show="tab === 'payments'" x-cloak>
            @if ($payments->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No payments yet.') }}</div>
            @else
                <div class="space-y-1 rounded-xl border border-gray-200 bg-white p-3 text-sm">
                    @foreach ($payments as $p)
                        <p class="flex items-center justify-between gap-2"><span>{{ date('d M', strtotime($p->paid_on)) }} · {{ $p->vendor }} · <b>{{ $tk($p->amount_bdt) }}</b> <span class="text-xs text-gray-400">{{ $p->method }} {{ $p->transaction_ref }}</span></span>
                            @can('marketing.create')<form method="POST" action="{{ route('usd-lots.payments.destroy', $p->id) }}" id="pay-del-{{ $p->id }}">@csrf @method('DELETE')<button type="button" @click="$dispatch('open-confirm', { id: 'payment-delete', form: 'pay-del-{{ $p->id }}', label: @js($tk($p->amount_bdt).' · '.$p->vendor) })" class="text-xs text-red-600 hover:underline">{{ __('Delete') }}</button></form>@endcan</p>
                    @endforeach
                </div>
            @endif
            </section>
        </div>

        @can('marketing.create')
            <div class="space-y-6">
                <x-card :title="__('Add a lot')" x-data="{ usd: '', rate: '', paid: '' }">
                    <form method="POST" action="{{ route('usd-lots.store') }}" class="space-y-2">
                        @csrf
                        <x-simple-select name="vendor_id" :options="$vendorOptions" :value="old('vendor_id')" :placeholder="__('Choose the vendor')" full-width class="w-full" />
                        <x-date-input name="purchased_on" :value="old('purchased_on', today()->toDateString())" :max="today()->toDateString()" :clearable="false" full-width />
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" name="usd" x-model="usd" step="0.01" min="1" required placeholder="{{ __('USD') }}" class="{{ $input }}">
                            <input type="number" name="rate" x-model="rate" step="0.01" min="50" required placeholder="{{ __('Rate (৳ per $)') }}" class="{{ $input }}">
                        </div>
                        <p class="text-xs text-gray-500" x-show="usd && rate">{{ __('Total') }}: ৳<span x-text="(usd * rate).toLocaleString()"></span></p>
                        <input type="number" name="paid_bdt" x-model="paid" step="0.01" min="0" placeholder="{{ __('Paid now (৳)') }}" class="{{ $input }}">
                        <div x-show="paid > 0" class="space-y-2">
                            <x-simple-select name="payment_method_id" :options="['' => __('How it was paid')] + $methods" value="" full-width class="w-full" />
                            <input name="transaction_ref" maxlength="100" placeholder="{{ __('Transaction ID') }}" class="{{ $input }}">
                        </div>
                        <div x-show="!(usd && rate) || paid < usd * rate">
                            <x-date-input name="due_date" :value="old('due_date')" :min="today()->toDateString()" :placeholder="__('Rest due on')" full-width />
                        </div>
                        <input name="note" maxlength="255" placeholder="{{ __('Note (optional)') }}" class="{{ $input }}">
                        <x-button class="w-full">{{ __('Save lot') }}</x-button>
                    </form>
                </x-card>

                <x-card :title="__('Pay a vendor')">
                    <form method="POST" action="{{ route('usd-lots.payments.store') }}" class="space-y-2">
                        @csrf
                        <x-simple-select name="vendor_id" :options="$vendorOptions" :value="old('vendor_id')" :placeholder="__('Choose the vendor')" full-width class="w-full" />
                        <input type="number" name="amount_bdt" step="0.01" min="1" required placeholder="{{ __('Amount (৳)') }}" class="{{ $input }}">
                        <x-date-input name="paid_on" :value="today()->toDateString()" :max="today()->toDateString()" :clearable="false" full-width />
                        <x-simple-select name="payment_method_id" :options="$methods" :value="old('payment_method_id')" :placeholder="__('How it was paid')" full-width class="w-full" />
                        <input name="transaction_ref" maxlength="100" placeholder="{{ __('Transaction ID') }}" class="{{ $input }}">
                        <x-button size="sm" class="w-full">{{ __('Record payment') }}</x-button>
                    </form>
                </x-card>

                <x-card :title="__('Add a vendor')">
                    <form method="POST" action="{{ route('usd-lots.vendors.store') }}" class="space-y-2">
                        @csrf
                        <input name="name" required maxlength="120" placeholder="{{ __('Vendor name') }}" class="{{ $input }}">
                        <input name="phone" placeholder="01XXXXXXXXX" class="{{ $input }}">
                        <div x-data="{ side: @js('due') }" class="space-y-1.5">
                        <p class="text-xs font-medium text-gray-600">{{ __('Balance before using the app (optional)') }}</p>
                        <input type="hidden" name="opening_side" :value="side">
                        <div class="flex gap-1.5">
                            <button type="button" @click="side = 'due'" class="flex-1 rounded-full border px-2 py-1 text-xs" :class="side === 'due' ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ __('We owe them') }}</button>
                            <button type="button" @click="side = 'advance'" class="flex-1 rounded-full border px-2 py-1 text-xs" :class="side === 'advance' ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ __('They hold our advance') }}</button>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5">
                            <input type="number" name="opening_amount" min="0" step="0.01" value="" placeholder="{{ __('Amount (৳)') }}" class="w-full {{ $input }}">
                            <x-date-input name="opening_on" :value="null" :max="today()->toDateString()" :placeholder="__('As of')" full-width />
                        </div>
                    </div>
                        <x-button size="sm" variant="secondary" class="w-full">{{ __('Add vendor') }}</x-button>
                    </form>
                </x-card>
            </div>
        @endcan
    </div>
    <x-confirm-modal id="lot-delete" :verb="__('Delete the lot')" :message="__('Only a lot with no dollar used yet can be deleted.')" />
    <x-confirm-modal id="payment-delete" :verb="__('Delete the payment')" :message="__('The vendor due goes back up by this amount.')" />
</x-layouts.app>
