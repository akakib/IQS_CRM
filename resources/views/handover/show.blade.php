<x-layouts.app :heading="__('Handover to :r', ['r' => $session->rider_name ?? __('rider')])">
    <div class="grid gap-6 lg:grid-cols-2"
        x-data="{
            last: null, count: {{ $handed->count() }}, total: {{ $handed->count() + $missing->count() }},
            async handle(code) {
                try {
                    const r = await fetch(@js(route('handover.scan', $session->id)), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ code }) });
                    this.last = await r.json();
                } catch (e) { this.last = { ok: false, level: 'red', message: @js(__('Connection problem. Scan again.')) }; }
                if (this.last.ok) { this.count++; }
                $dispatch('scan-result', { ok: this.last.ok, message: this.last.message });
            },
        }" @scan="handle($event.detail)">
        <div>
            <x-scan-input :placeholder="__('Scan each parcel as the rider takes it')" />
            <div x-show="last" x-cloak class="mt-4 rounded-xl border-2 p-4"
                :class="{ 'border-green-600 bg-green-50': last?.level === 'ok', 'border-purple-600 bg-purple-50': last?.level === 'edited', 'border-orange-500 bg-orange-50': ['orange', 'warn'].includes(last?.level), 'border-red-600 bg-red-50': last?.level === 'red' }">
                <p class="text-lg font-bold" x-text="last?.message"></p>
                <p class="font-mono text-sm" x-text="last?.order?.order_no"></p>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs uppercase text-gray-500">{{ __('To hand over') }}</p><p class="text-2xl font-semibold tabular-nums" x-text="total"></p></div>
                <div class="rounded-xl border border-green-200 bg-green-50 p-4"><p class="text-xs uppercase text-green-800">{{ __('Scanned') }}</p><p class="text-2xl font-semibold tabular-nums text-green-900" x-text="count"></p></div>
                <div class="rounded-xl border p-4" :class="total - count > 0 ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white'"><p class="text-xs uppercase text-gray-500">{{ __('Left') }}</p><p class="text-2xl font-semibold tabular-nums" x-text="Math.max(0, total - count)"></p></div>
            </div>
            <form method="POST" action="{{ route('handover.close', $session->id) }}" class="mt-4">@csrf<x-button class="w-full">{{ __('Finish handover') }}</x-button></form>
            <p class="mt-1 text-center text-xs text-gray-500">{{ __('Parcels not scanned move to the next pickup.') }}</p>
        </div>

        <x-card :title="__('Ready but not scanned yet')">
            @forelse ($missing as $m)
                <div class="border-b border-gray-50 py-1.5 font-mono text-sm last:border-0">{{ $m->order_no }}</div>
            @empty
                <p class="text-sm text-gray-400">{{ __('Everything ready has been scanned.') }}</p>
            @endforelse
            <p class="mt-2 text-xs text-gray-400">{{ __('Reload to refresh this list.') }}</p>
        </x-card>
    </div>
</x-layouts.app>
