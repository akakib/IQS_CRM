<x-layouts.app :heading="__('Courier booking')">
    @if ($courier === 'fake')
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ __('Test mode: bookings use the fake courier. No real Steadfast parcel is created.') }}</div>
    @endif

    <x-tabs :tabs="['book' => [__('Ready to book'), '#book', $orders->count()], 'booked' => [__('Booked, waiting for packing'), '#booked', $booked->count()]]" active="book" />

    <section id="book" class="mb-8">
        @if ($orders->isEmpty())
            <x-empty-state :message="__('No confirmed orders waiting for booking.')" />
        @else
            <x-list.selectable :ids="$orders->pluck('id')">
                <x-list.table>
                    <x-slot:head><th class="w-8"></th><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Area') }}</th><th class="text-right">{{ __('COD') }}</th><th>{{ __('Weight') }}</th><th>{{ __('Confirmed') }}</th></x-slot:head>
                    @foreach ($orders as $o)
                        <tr>
                            <td><x-list.check :id="$o->id" /></td>
                            <td><a href="{{ route('orders.show', $o) }}" class="font-mono hover:underline">{{ $o->order_no }}</a> <x-order-status :order="$o" :statuses="$statuses" /></td>
                            <td>{{ $o->ship_name }}</td>
                            <td class="text-gray-600">{{ collect([$o->ship_thana, $o->ship_district])->filter()->join(', ') }}</td>
                            <td class="text-right tabular-nums">৳{{ number_format((float) $o->cod_amount) }}</td>
                            <td class="tabular-nums text-gray-600">{{ number_format($o->total_weight_g / 1000, 2) }} kg</td>
                            <td class="text-gray-500">{{ $o->confirmed_at?->format('d M, g:i A') }}</td>
                        </tr>
                    @endforeach
                </x-list.table>
                <x-list.cards>
                    @foreach ($orders as $o)
                        <x-record-card :title="$o->order_no.' · '.$o->ship_name" :subtitle="collect([$o->ship_thana, $o->ship_district])->filter()->join(', ')">
                            <x-slot:badge><div class="flex items-center gap-2"><span class="font-semibold">৳{{ number_format((float) $o->cod_amount) }}</span><x-list.check :id="$o->id" /></div></x-slot:badge>
                        </x-record-card>
                    @endforeach
                </x-list.cards>
                <x-slot:actions>
                    <form method="POST" action="{{ route('shipping.book') }}">
                        @csrf
                        <x-list.selected-inputs />
                        <button class="rounded-lg bg-green-700 px-3 py-1.5 text-xs font-medium hover:bg-green-600">{{ __('Book and print labels') }}</button>
                    </form>
                </x-slot:actions>
            </x-list.selectable>
        @endif
    </section>

    <section id="booked">
        <h2 class="mb-2 text-sm font-semibold text-gray-800">{{ __('Booked, waiting for packing') }}</h2>
        @if ($booked->isEmpty())
            <p class="text-sm text-gray-400">{{ __('Nothing booked yet.') }}</p>
        @else
            <x-list.selectable :ids="$booked->pluck('order_no')">
                <x-list.table>
                    <x-slot:head><th class="w-8"></th><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>CN</th><th class="text-right">{{ __('COD') }}</th><th></th></x-slot:head>
                    @foreach ($booked as $o)
                        <tr>
                            <td><x-list.check :id="$o->order_no" /></td>
                            <td><a href="{{ route('orders.show', $o) }}" class="font-mono hover:underline">{{ $o->order_no }}</a> <x-order-status :order="$o" :statuses="$statuses" />
                                @if ($o->label_version < $o->current_version)<x-badge color="amber">{{ __('Label out of date') }}</x-badge>@endif</td>
                            <td>{{ $o->ship_name }}</td>
                            <td class="font-mono text-gray-600">{{ $consignments[$o->active_shipment_id] ?? '-' }}</td>
                            <td class="text-right tabular-nums">৳{{ number_format((float) $o->cod_amount) }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('shipping.reprint', $o) }}">@csrf<button class="text-xs text-green-900 hover:underline">{{ __('New label') }}</button></form>
                            </td>
                        </tr>
                    @endforeach
                </x-list.table>
                <x-list.cards>
                    @foreach ($booked as $o)
                        <x-record-card :title="$o->order_no.' · '.$o->ship_name" :subtitle="'CN '.($consignments[$o->active_shipment_id] ?? '-')">
                            <x-slot:badge><x-list.check :id="$o->order_no" /></x-slot:badge>
                        </x-record-card>
                    @endforeach
                </x-list.cards>
                <x-slot:actions>
                    <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs" @click="window.open(@js(route('shipping.labels')) + '?orders=' + selected.join(','), '_blank')">{{ __('Print labels again') }}</button>
                </x-slot:actions>
            </x-list.selectable>
        @endif
    </section>
</x-layouts.app>
