@php
    $stages = ['order_created' => __('Order created'), 'record_verified' => __('Record verified'), 'confirmed' => __('Confirmed'), 'delivered' => __('Delivered')];
    $bases = ['order_total' => __('Order total'), 'product_subtotal' => __('Products after discount'), 'delivered_amount' => __('Amount collected')];
    $channels = ['web' => __('Website'), 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'phone' => __('Phone'), 'b2b' => 'B2B'];
@endphp

<x-layouts.app :heading="__('Ad tracking')">
    <div class="mb-4 rounded-xl border p-4 text-sm {{ $metaReady && $production ? 'border-green-200 bg-primary-soft text-primary' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
        @if ($metaReady && $production)
            {{ __('Meta Conversions API is live: events are sent to Meta.') }}
        @else
            {{ __('Test mode: events are recorded below but not sent to Meta (needs production with META_PIXEL_ID and META_CAPI_TOKEN).') }}
        @endif
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($events as $e)
            @php($chosen = json_decode($e->channels, true) ?: [])
            <x-card :title="'Meta · '.$e->event_name">
                <form method="POST" action="{{ route('settings.tracking.update', $e->id) }}" class="space-y-3" x-data="{ fire: @js($e->fire_on), basis: @js($e->value_basis) }">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="fire_on" :value="fire">
                    <input type="hidden" name="value_basis" :value="basis">
                    <div>
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Send when the order is') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($stages as $key => $label)
                                <button type="button" @click="fire = @js($key)" class="rounded-full border px-3 py-1 text-sm" :class="fire === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                            @endforeach
                        </div>
                        <p x-show="fire === 'delivered'" class="mt-2 text-xs text-amber-700">{{ __('Turn off the browser Purchase on the thank-you page (or rename it), otherwise Meta cannot match them days later. Meta only accepts events up to 7 days old.') }}</p>
                        <p x-show="fire !== 'delivered'" class="mt-2 text-xs text-gray-500">{{ __('The website pixel must use the same event ID (Settings > General > Tracking) so Meta counts the sale once.') }}</p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Value') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($bases as $key => $label)
                                <button type="button" @click="basis = @js($key)" class="rounded-full border px-3 py-1 text-sm" :class="basis === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('For orders from') }}</p>
                        <div class="flex flex-wrap gap-3">
                            @foreach ($channels as $key => $label)
                                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="channels[]" value="{{ $key }}" @checked(in_array($key, $chosen, true)) class="rounded border-gray-300 text-primary"> {{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($e->is_active) class="rounded border-gray-300 text-primary"> {{ __('On') }}</label>
                    @can('settings.edit')<x-button>{{ __('Save') }}</x-button>@endcan
                </form>
            </x-card>
        @endforeach

        <x-card :title="__('Last events')">
            @forelse ($logs as $l)
                <div class="flex items-center justify-between gap-2 border-b border-gray-50 py-1.5 text-sm last:border-0">
                    <span><a href="{{ route('orders.show', $l->order_id) }}" class="font-mono hover:underline">{{ $l->order_no }}</a> · {{ $l->event_name }} · ৳{{ number_format((float) $l->value) }}
                        <span class="block font-mono text-xs text-gray-400">{{ $l->event_id }}</span></span>
                    <x-badge :color="['sent' => 'green', 'failed' => 'red', 'queued' => 'blue', 'skipped' => 'gray'][$l->status]">{{ ucfirst($l->status) }}{{ str_contains((string) $l->response, '"fake":true') ? ' (test)' : '' }}</x-badge>
                </div>
            @empty
                <p class="text-sm text-gray-400">{{ __('No events yet.') }}</p>
            @endforelse
        </x-card>
    </div>
</x-layouts.app>
