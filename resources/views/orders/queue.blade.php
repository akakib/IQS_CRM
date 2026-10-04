@php use App\Support\Permissions\Mask; @endphp

<x-layouts.app :heading="__('Call queue')">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count order to call|:count orders to call', $orders->count(), ['count' => $orders->count()]) }} · {{ trans_choice(':count waiting to be taken|:count waiting to be taken', $waiting, ['count' => $waiting]) }}</p>
        @if ($waiting)
            <form method="POST" action="{{ route('orders.queue.next') }}">@csrf<x-button>{{ __('Take next order') }}</x-button></form>
        @endif
    </div>
    @error('order')<div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $message }}</div>@enderror

    @if ($orders->isEmpty())
        <x-empty-state :message="__('Nothing to call right now.')" />
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($orders as $o)
            @php
                $tries = (int) ($attempts[$o->id]->n ?? 0);
                $preorder = $o->items->first(fn ($i) => $i->variant?->availability_status === 'backorder');
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-4" x-data="{ more: null, reason: '' }">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <a href="{{ route('orders.show', $o) }}" class="font-mono text-sm font-semibold text-gray-800 hover:underline">{{ $o->order_no }}</a>
                        <x-order-status :order="$o" :statuses="$statuses" />
                        @if ($tries)<x-badge :color="$tries >= $maxNoAnswer ? 'red' : 'amber'">{{ __(':n/:m tries', ['n' => $tries, 'm' => $maxNoAnswer]) }}</x-badge>@endif
                    </div>
                    <p class="text-lg font-semibold tabular-nums">৳{{ number_format((float) $o->cod_amount) }}</p>
                </div>

                <div class="mt-2 flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-800">{{ $o->ship_name }} <span class="text-xs font-normal text-gray-500">· {{ collect([$o->ship_thana, $o->ship_district])->filter()->join(', ') }}</span></p>
                        <p class="text-xs text-gray-500">{{ __(':o orders · :d delivered · :r returned', ['o' => $o->customer->orders_count, 'd' => $o->customer->delivered_count, 'r' => $o->customer->returned_count]) }}</p>
                    </div>
                    <a href="tel:{{ $o->ship_phone }}" class="shrink-0 rounded-lg bg-green-900 px-3 py-2 font-mono text-sm text-white">☎ {{ Mask::value($o->ship_phone, 'customer_contact') }}</a>
                </div>

                <p class="mt-2 text-sm text-gray-600">{{ $o->items->map(fn ($i) => $i->name_snapshot.' ×'.rtrim(rtrim($i->qty, '0'), '.'))->join(', ') }}</p>
                @if ($preorder)<p class="mt-1 text-xs font-medium text-amber-700">{{ __('Pre-order item, expected :d. Tell the customer.', ['d' => $preorder->variant->expected_restock_date?->format('d M') ?? '?']) }}</p>@endif
                @if ($o->customer_note)<p class="mt-1 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">{{ $o->customer_note }}</p>@endif

                <form method="POST" action="{{ route('orders.queue.call', $o) }}" class="mt-3 border-t border-gray-100 pt-3" @select-change="reason = $event.detail ?? ''">
                    @csrf
                    <input type="hidden" name="reason_id" :value="reason">
                    <input name="note" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="mb-2 w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <div x-show="more === 'hold'" x-cloak class="mb-2"><x-simple-select :options="['' => __('Hold reason')] + $reasons['hold']" value="" full-width class="w-full" /></div>
                    <div x-show="more === 'cancelled'" x-cloak class="mb-2"><x-simple-select :options="['' => __('Cancel reason')] + $reasons['cancel']" value="" full-width class="w-full" /></div>
                    <div class="flex flex-wrap gap-2">
                        @if ($statuses[$o->status_id]['key'] !== 'new')
                            <button name="outcome" value="confirmed" class="rounded-lg bg-green-700 px-3 py-1.5 text-sm font-medium text-white">{{ __('Confirmed') }}</button>
                        @endif
                        <button name="outcome" value="no_answer" @disabled($tries >= $maxNoAnswer) class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-40">{{ __('No answer') }}</button>
                        <button name="outcome" value="callback" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">{{ __('Call back later') }}</button>
                        <button type="button" @click="more = 'hold'" x-show="more !== 'hold'" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">{{ __('Hold…') }}</button>
                        <button name="outcome" value="hold" x-show="more === 'hold'" x-cloak class="rounded-lg bg-amber-700 px-3 py-1.5 text-sm text-white">{{ __('Save hold') }}</button>
                        <button type="button" @click="more = 'cancelled'" x-show="more !== 'cancelled'" class="rounded-lg border border-red-200 px-3 py-1.5 text-sm text-red-600">{{ __('Cancel…') }}</button>
                        <button name="outcome" value="cancelled" x-show="more === 'cancelled'" x-cloak class="rounded-lg bg-red-600 px-3 py-1.5 text-sm text-white">{{ __('Save cancel') }}</button>
                    </div>
                    @if ($statuses[$o->status_id]['key'] === 'new')
                        <p class="mt-2 text-xs text-gray-500">{{ __('Automatic checks did not verify this order. A manager decides whether it goes ahead.') }}</p>
                    @endif
                </form>
            </div>
        @endforeach
    </div>
</x-layouts.app>
