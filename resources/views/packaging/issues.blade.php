<x-layouts.app :heading="__('Missing-item reports')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Packers only report. Decide here: Out of stock or Pre-order (open orders move to Hold automatically), or dismiss if the item is there.') }}</p>

    @if ($reports->isEmpty())
        <x-empty-state :message="__('No open reports.')" />
    @endif

    <div class="space-y-3">
        @foreach ($reports as $r)
            <div class="rounded-xl border border-red-200 bg-white p-4" x-data="{ d: null }">
                <p class="font-medium text-gray-800">{{ $r->product }}{{ $r->variant !== 'Default' ? ' · '.$r->variant : '' }} <span class="font-mono text-xs text-gray-400">{{ $r->sku }} {{ $r->shelf_code ? '· '.$r->shelf_code : '' }}</span></p>
                <p class="text-xs text-gray-500">{{ __('Reported by :n', ['n' => $r->reporter ?? '-']) }} · {{ \Illuminate\Support\Carbon::parse($r->created_at)->diffForHumans() }}{{ $r->order_no ? ' · '.$r->order_no : '' }}{{ $r->note ? ' · '.$r->note : '' }}</p>
                @can('products.availability')
                    <form method="POST" action="{{ route('packaging.issues.resolve', $r->id) }}" id="stock-issue-{{ $r->id }}" class="mt-3 flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="hidden" name="decision" :value="d">
                        @foreach (['out_of_stock' => __('Out of stock'), 'backorder' => __('Pre-order'), 'dismiss' => __('It is there (dismiss)')] as $key => $label)
                            <button type="button" @click="d = @js($key)" class="rounded-full border px-3 py-1 text-xs" :class="d === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                        @endforeach
                        <div x-show="d && d !== 'dismiss'"><x-date-input name="expected_restock_date" :min="now()->toDateString()" :placeholder="__('Expected date')" size="sm" /></div>
                        <x-button type="button" size="sm" x-bind:disabled="!d"
                            @click="d === 'dismiss' ? $el.closest('form').submit() : $dispatch('open-confirm', { id: 'stock-issue-confirm', form: 'stock-issue-{{ $r->id }}', label: @js($r->product), verb: d === 'backorder' ? @js(__('Pre-order')) : @js(__('Out of stock')) })">{{ __('Save') }}</x-button>
                    </form>
                @endcan
            </div>
        @endforeach
    </div>
    <x-confirm-modal id="stock-issue-confirm" :verb="__('Out of stock')" :message="__('Every open order with this item moves to Hold.')" />
</x-layouts.app>
