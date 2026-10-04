<x-layouts.app :heading="__('Scan to pack')">
    <div class="mx-auto max-w-xl"
        x-data="{
            last: null, history: [],
            async handle(code) {
                try {
                    const r = await fetch(@js(route('packing.scan.post')), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ code }) });
                    this.last = await r.json();
                } catch (e) { this.last = { ok: false, level: 'red', message: @js(__('Connection problem. Scan again.')) }; }
                this.history.unshift({ code, ...this.last }); this.history = this.history.slice(0, 15);
                $dispatch('scan-result', { ok: this.last.ok, message: this.last.message });
            },
        }" @scan="handle($event.detail)">
        <x-scan-input :placeholder="__('Scan the parcel label')" />

        <div x-show="last" x-cloak class="mt-4 rounded-xl border-2 p-4"
            :class="{ 'border-green-600 bg-green-50': last?.level === 'ok', 'border-purple-600 bg-purple-50': last?.level === 'edited', 'border-orange-500 bg-orange-50': last?.level === 'orange' || last?.level === 'warn', 'border-red-600 bg-red-50': last?.level === 'red' }">
            <p class="text-lg font-bold" x-text="last?.message"></p>
            <template x-if="last?.order">
                <div class="mt-2">
                    <p class="font-mono text-sm" x-text="last.order.order_no"></p>
                    <ul class="mt-1 list-inside list-disc text-sm"><template x-for="i in last.order.items" :key="i"><li x-text="i"></li></template></ul>
                </div>
            </template>
        </div>

        <div class="mt-6">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('This session') }}</p>
            <template x-for="(h, n) in history" :key="n">
                <div class="flex justify-between border-b border-gray-100 py-1 text-sm">
                    <span class="font-mono" x-text="h.code"></span>
                    <span :class="h.ok ? 'text-green-800' : 'text-red-700'" x-text="h.message"></span>
                </div>
            </template>
        </div>
    </div>
</x-layouts.app>
