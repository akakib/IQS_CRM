@php
    use App\Http\Controllers\CustomerCallController;
    use App\Models\Order;
    use App\Support\Permissions\Mask;
    $riskColor = ['normal' => 'gray', 'watch' => 'amber', 'blocked' => 'red'];
    $tk = fn ($n) => '৳'.number_format((float) $n);
    $palette = Order::CHANNEL_COLORS;
    $lostN = $stats['returned'][0] + $stats['cancelled'][0];
    $lostAmt = $stats['returned'][1] + $stats['cancelled'][1];
    $imported = $customer->importedCounts();
    $mayHear = fn ($c) => $c->proof_path && (auth()->id() === $c->user_id || auth()->user()->can('calls.manage'));
@endphp

{{-- The one page of a customer: Overview, Journey, Orders, Calls. --}}
<x-layouts.app :heading="$customer->name">
    <div class="mx-auto max-w-5xl">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            @if ($backToResults)
                <a href="{{ $backToResults }}" class="text-sm text-primary hover:underline">{{ __('Back to results') }}</a>
            @else
                <a href="{{ route('customers.index') }}" class="text-sm text-primary hover:underline">{{ __('Back to customers') }}</a>
            @endif
            <div class="flex flex-wrap items-center gap-2">
                @can('orders.create')
                    @php $firstPhone = optional($customer->phones->first())->phone; @endphp
                    <x-button type="button" size="sm" @click="window.dispatchEvent(new CustomEvent('order-new', { detail: {{ \Illuminate\Support\Js::from(route('orders.create', array_filter(['phone' => $firstPhone, 'embed' => 1]))) }} }))">+ {{ __('New order') }}</x-button>
                @endcan
                @can('customers.edit')
                    @if ($canSeeContact)<x-button size="sm" variant="secondary" :href="route('customers.edit', $customer)">{{ __('Edit') }}</x-button>@endif
                    <x-button size="sm" type="button" variant="secondary" @click="$dispatch('open-modal', 'merge')">{{ __('Merge duplicate') }}</x-button>
                @endcan
            </div>
        </div>

        {{-- Who, and the numbers that matter: the same counts as the search and every other screen. --}}
        <div class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $customer->name }}</h2>
                    <p class="text-sm text-gray-500">
                        <span class="font-mono">{{ Mask::value($customer->primary_phone, 'customer_contact') }}</span>
                        @if ($area) · {{ $area }}@endif
                        @if ($stats['first']) · {{ __('customer since :d', ['d' => $stats['first']->format('d M Y')]) }}@endif
                    </p>
                </div>
                <x-badge :color="$riskColor[$customer->risk_level] ?? 'gray'">{{ \App\Support\Labels::risk($customer->risk_level) }}</x-badge>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Delivered sales') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900">{{ $tk($stats['sold']) }}</p></div>
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Orders') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900">{{ $stats['orders'] }} <span class="text-xs font-normal text-gray-500">{{ __(':d delivered', ['d' => $stats['delivered']]) }}</span></p>
                    @if ($imported['orders'])<p class="text-xs text-gray-500">{{ __(':i in IQS · :f from files', ['i' => $customer->iqs_orders_count, 'f' => $imported['orders']]) }}</p>@endif</div>
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Cancel / return') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums {{ $lostN ? 'text-red-700' : 'text-gray-900' }}">{{ $lostN }} · {{ $tk($lostAmt) }}</p></div>
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Orders every') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900">{{ $stats['gap'] === null ? '-' : trans_choice(':count day|:count days', $stats['gap'], ['count' => $stats['gap']]) }}</p></div>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                {{ __(':c cancelled (:a)', ['c' => $stats['cancelled'][0], 'a' => $tk($stats['cancelled'][1])]) }}
                · {{ __(':c returned (:a)', ['c' => $stats['returned'][0], 'a' => $tk($stats['returned'][1])]) }}
                · {{ __('Refunded :a', ['a' => $tk($stats['refunded'])]) }}
                @if ($stats['last']) · {{ __('last order :d', ['d' => $stats['last']->diffForHumans()]) }}@endif
            </p>
        </div>

        <div x-data="tabs('overview')">
            <x-tabs :tabs="['overview' => [__('Overview'), '#overview'], 'journey' => [__('Journey'), '#journey'], 'orders' => [__('Orders'), '#orders', $stats['orders']], 'calls' => [__('Calls'), '#calls', $calls->count()]]" active="overview" />

            {{-- Overview: where orders come from, contact, addresses, fraud check. --}}
            <section x-show="tab === 'overview'" class="space-y-4">
                @if ($channels->isNotEmpty())
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
                        <p class="text-xs font-medium text-gray-600">{{ __('Where the orders came from') }}</p>
                        <div class="mt-1.5 flex h-2.5 overflow-hidden rounded-full bg-gray-100">
                            @foreach ($channels as $ch => $n)<span style="width: {{ $n / max(1, $stats['orders']) * 100 }}%; background: {{ $palette[$ch] ?? '#9ca3af' }}"></span>@endforeach
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-600">
                            @foreach ($channels as $ch => $n)<span class="inline-flex items-center gap-1"><i class="inline-block h-2 w-2 rounded-sm" style="background: {{ $palette[$ch] ?? '#9ca3af' }}"></i>{{ Order::channelLabel($ch) }} {{ $n }}</span>@endforeach
                        </div>
                    </div>
                @endif
                <div class="grid gap-4 lg:grid-cols-2">
            <x-card :title="__('Contact')">
                <dl class="space-y-2 text-sm">
                    @foreach ($customer->phones as $p)
                        <div class="flex justify-between"><dt class="text-gray-500">{{ $p->is_primary ? __('Primary phone') : __('Other phone') }}</dt><dd class="font-mono">{{ Mask::value($p->phone, 'customer_contact') }}</dd></div>
                    @endforeach
                    @if ($customer->whatsapp_number)
                        <div class="flex justify-between"><dt class="text-gray-500">WhatsApp</dt><dd class="font-mono">{{ Mask::value($customer->whatsapp_number, 'customer_contact') }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('Marketing consent') }}</dt><dd>{{ $customer->marketing_consent ? __('Yes, since :d', ['d' => $customer->consent_at?->format('d M Y')]) : __('No') }}</dd></div>
                    @if ($customer->note)<div><dt class="text-gray-500">{{ __('Note') }}</dt><dd class="mt-1 text-gray-700">{{ $customer->note }}</dd></div>@endif
                </dl>
                <p class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Addresses') }}</p>
                @forelse ($customer->addresses as $a)
                    <div class="mb-2 rounded-lg bg-gray-50 p-3 text-sm">
                        @if ($canSeeContact){{ $a->oneLine() }}@else<span class="text-gray-400">{{ __('Hidden for your role') }}</span>@endif
                        <span class="ml-1 text-xs text-gray-500">{{ $a->zone?->name }}{{ $a->is_default ? ' · '.__('default') : '' }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">{{ __('No address yet.') }}</p>
                @endforelse
            </x-card>

            <x-card :title="__('Delivery history (fraud check)')">
                <x-slot:actions>
                    @can('customers.view')
                        <form method="POST" action="{{ route('customers.fraud-check', $customer) }}">@csrf<x-button size="sm" variant="secondary">{{ __('Check now') }}</x-button></form>
                    @endcan
                </x-slot:actions>
                @forelse ($latest as $check)
                    <div class="mb-3 flex items-center justify-between rounded-lg border border-gray-100 p-3">
                        <div>
                            <p class="text-sm font-medium text-gray-800">{{ $check->provider->name }}</p>
                            <p class="text-xs text-gray-500">{{ __(':t parcels · :d delivered · :c cancelled', ['t' => $check->total_parcels ?? 0, 'd' => $check->delivered ?? 0, 'c' => $check->cancelled ?? 0]) }} · {{ $check->checked_at->diffForHumans() }}</p>
                        </div>
                        @php $rate = $check->success_rate; @endphp
                        <x-badge :color="$rate === null ? 'gray' : ($rate >= 80 ? 'green' : ($rate >= 50 ? 'amber' : 'red'))">{{ $rate === null ? __('No history') : rtrim(rtrim($rate, '0'), '.').'%' }}</x-badge>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">{{ __('Not checked yet.') }}</p>
                @endforelse
                <p class="mt-2 text-xs text-gray-400">{{ __('A high rate with very few parcels says little; rules pair the rate with a minimum parcel count.') }}</p>
            </x-card>
                </div>
            </section>

            <section x-show="tab === 'journey'" x-cloak class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
                <p class="mb-3 text-xs text-gray-500">{{ __('Orders, imported orders and calls, newest first.') }}</p>
                @include('customers._timeline')
            </section>

            {{-- Orders: in IQS, then the ones imported from files. --}}
            <section x-show="tab === 'orders'" x-cloak class="space-y-4">
                <x-card :title="__('Orders in IQS')">
                    @forelse ($orders as $o)
                        @php $s = $statuses[$o->status_id] ?? null; @endphp
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 text-sm last:border-0">
                            <span>@if (isset($openable[$o->id]))<a href="{{ route('orders.show', $o->id) }}" class="font-mono font-medium text-primary hover:underline">{{ $o->order_no }}</a>@else<span class="font-mono font-medium text-gray-800">{{ $o->order_no }}</span>@endif
                                <span class="text-xs text-gray-500">· {{ $o->created_at->format('d M Y') }} · {{ Order::channelLabel($o->channel) }}</span></span>
                            <span class="flex items-center gap-2">@if ($s)<x-badge :color="$s['color']">{{ __($s['name']) }}</x-badge>@endif<span class="tabular-nums text-gray-700">{{ $tk($o->grand_total) }}</span></span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No orders in IQS yet.') }}</p>
                    @endforelse
                </x-card>
                @if ($past->isNotEmpty())
                    <x-card :title="__('Imported from files')">
                        @foreach ($past as $p)
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 text-sm last:border-0">
                                <span><span class="font-mono text-gray-800">{{ $p->cn ? 'CN '.$p->cn : ($p->external_no ? '#'.$p->external_no : '-') }}</span>
                                    <span class="text-xs text-gray-500">· {{ $p->at->format('d M Y') }} · {{ $p->source }}</span>@if ($p->items)<span class="block text-xs text-gray-500">{{ $p->items }}</span>@endif</span>
                                <span class="flex items-center gap-2"><x-badge :color="$p->status === 'delivered' ? 'green' : ($p->status === 'pending' ? 'gray' : 'red')">{{ __(\App\Services\Customers\HistoryImport::STATUSES[$p->status] ?? $p->status) }}</x-badge><span class="tabular-nums text-gray-700">{{ $tk($p->amount) }}</span></span>
                            </div>
                        @endforeach
                    </x-card>
                @endif
            </section>

            {{-- Calls: in and out, with what was said and the recording. --}}
            <section x-show="tab === 'calls'" x-cloak>
                <x-card :title="__('Calls')">
                    @forelse ($calls as $c)
                        <div class="border-b border-gray-100 py-2.5 text-sm last:border-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium text-gray-900">{{ $c->direction === 'in' ? __('Call from customer') : __('Call to customer') }}
                                    <x-badge color="amber">{{ __(CustomerCallController::OUTCOMES[$c->outcome] ?? $c->outcome) }}</x-badge>
                                    @if ($c->satisfaction !== null)<x-badge :color="$c->satisfaction < 50 ? 'red' : ($c->satisfaction < 75 ? 'amber' : 'green')">{{ __(':s% satisfied', ['s' => $c->satisfaction]) }}</x-badge>@endif</span>
                                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($c->started_at)->format('d M Y, g:i A') }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500">{{ __('By :n', ['n' => $c->by ?? '-']) }}@if ($c->duration_seconds) · {{ intdiv($c->duration_seconds, 60) }}m {{ $c->duration_seconds % 60 }}s @endif @if ($c->order_no) · {{ $c->order_no }}@endif</p>
                            @if ($c->note)<p class="mt-1 text-gray-700">{{ $c->note }}</p>@endif
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                @if ($mayHear($c))<x-play-call :call="$c" :title="$customer->name" :sub="($c->by ?? '').' · '.\Illuminate\Support\Carbon::parse($c->started_at)->format('d M, g:i A')" />@if ($c->proof_old)<span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">{{ __('Earlier file') }}</span>@endif
                                @elseif ($c->recording_url)<a href="{{ $c->recording_url }}" target="_blank" rel="noopener" class="text-xs text-primary hover:underline">{{ __('Recording') }}</a>@endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No calls yet.') }}</p>
                    @endforelse
                </x-card>
            </section>
        </div>
    </div>

    @can('customers.edit')
        <x-modal id="merge" :title="__('Merge a duplicate into :name', ['name' => $customer->name])" persistent>
            <form method="POST" action="{{ route('customers.merge', $customer) }}" id="merge-form">
                @csrf
                <p class="mb-3 text-sm text-gray-600">{{ __('Enter any phone number of the duplicate customer. Their numbers, addresses, history and orders move here; the duplicate is removed.') }}</p>
                <input name="duplicate_phone" required inputmode="tel" placeholder="01XXXXXXXXX" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
            </form>
            <x-slot:footer>
                <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'merge')">{{ __('Cancel') }}</x-button>
                <x-button type="button" @click="document.getElementById('merge-form').reportValidity() && $dispatch('open-confirm', { id: 'merge-confirm', form: 'merge-form', label: document.querySelector('#merge-form [name=duplicate_phone]').value })">{{ __('Merge') }}</x-button>
            </x-slot:footer>
        </x-modal>
        <x-confirm-modal id="merge-confirm" :verb="__('Merge')" :message="__('The other customer is removed for good; everything moves here.')" />
    @endcan
</x-layouts.app>
