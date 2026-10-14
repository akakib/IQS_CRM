@php use App\Support\Permissions\Mask; @endphp

<x-layouts.app :heading="__('Order search')">
    <form method="GET" action="{{ route('orders.search') }}" class="mb-4 space-y-2" x-data="{ by: @js($by) }">
        {{-- Search in: only the picked field (All looks at every one). --}}
        <input type="hidden" name="by" :value="by">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-gray-500">{{ __('Search in') }}</span>
            @foreach (['all' => __('All'), 'cn' => __('CN'), 'phone' => __('Phone'), 'name' => __('Name')] as $key => $label)
                <button type="button" @click="by = @js($key); $nextTick(() => { if ($root.querySelector('[name=q]').value.trim()) $root.submit() })"
                    class="rounded-full border px-3 py-1.5" :class="by === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600 hover:bg-gray-50'">{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex gap-2">
        <input type="search" name="q" value="{{ $q }}" autofocus autocomplete="off" placeholder="{{ __('Order number, phone, CN or customer name') }}"
            class="min-w-0 flex-1 rounded-lg border border-gray-300 px-4 py-3 text-base focus:border-primary focus:outline-none">
        <x-button class="shrink-0">{{ __('Search') }}</x-button>
        @if ($q !== '')
            <a href="{{ route('orders.search') }}" class="flex shrink-0 items-center rounded-lg border border-gray-300 px-4 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
        @endif
        </div>
    </form>

    @if ($q === '')
        <x-empty-state :message="__('Type an order number (IQ10313 or 10313), a phone, a CN or a customer name, then press Search.')" />
    @elseif ($tooShort)
        <x-empty-state :message="__('Type at least 3 characters.')" />
    @elseif ($results->isEmpty())
        <x-empty-state :message="__('No order found for :q.', ['q' => $q])" />
    @else
        <p class="mb-2 text-xs text-gray-500">{{ trans_choice(':count order found|:count orders found', $results->count(), ['count' => $results->count()]) }}@if ($results->count() === 25) · {{ __('showing the newest 25') }}@endif</p>
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($results as $o)
                @php $canOpen = isset($openable[$o->id]); @endphp
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">
                                @if ($canOpen)<a href="{{ route('orders.show', $o) }}" class="font-mono hover:text-primary hover:underline">{{ $o->order_no }}</a>@else<span class="font-mono">{{ $o->order_no }}</span>@endif
                                · {{ $o->ship_name }}
                            </p>
                            <p class="text-xs text-gray-500"><span class="font-mono">{{ Mask::value($o->ship_phone, 'customer_contact') }}</span>
                                @if ($o->ship_thana || $o->ship_district) · {{ collect([$o->ship_thana, $o->ship_district])->filter()->join(', ') }}@endif
                                · {{ $o->created_at->format('d M Y, g:i A') }}</p>
                        </div>
                        <x-order-status :order="$o" :statuses="$statuses" />
                    </div>
                    <p class="mt-2 text-sm text-gray-700">{{ $o->items->map(fn ($i) => $i->name_snapshot.' × '.\App\Support\Units::qty($i->qty, $i->unit))->join(', ') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
                        <span>{{ __('Total') }} <b class="tabular-nums text-gray-900">৳{{ number_format((float) $o->grand_total) }}</b></span>
                        <span>{{ __('COD') }} <b class="tabular-nums text-gray-900">৳{{ number_format((float) $o->cod_amount) }}</b></span>
                        @if ($cns[$o->id] ?? null)<span>CN <b class="font-mono text-gray-900">{{ $cns[$o->id] }}</b></span>@endif
                        <span>{{ \App\Models\Order::channelLabel($o->channel) }}</span>
                        <span>{{ __('With') }} {{ $o->moderator?->name ?? __('nobody') }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
