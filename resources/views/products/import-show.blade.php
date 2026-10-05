<x-layouts.app :heading="__('Import: :f', ['f' => $import->file_name])">
    <x-products.subnav active="import" />

    <div class="max-w-2xl" x-data="{
            s: { status: @js($import->status), rows_done: {{ (int) $import->rows_done }}, rows_total: {{ (int) $import->rows_total }}, created: {{ (int) $import->created_count }}, updated: {{ (int) $import->updated_count }}, skipped: {{ (int) $import->skipped_count }}, errors: @js(array_slice(json_decode($import->errors ?? '[]', true) ?: [], 0, 50)) },
            failedRequests: 0,
            get pct() { return this.s.rows_total ? Math.min(100, Math.round(this.s.rows_done * 100 / this.s.rows_total)) : (this.s.status === 'done' ? 100 : 0) },
            async run() {
                while (['pending', 'running'].includes(this.s.status)) {
                    try {
                        const r = await fetch(@js(route('products.import.step', $import->id)), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                        if (!r.ok) throw new Error(r.status);
                        this.s = await r.json(); this.failedRequests = 0;
                    } catch (e) {
                        if (++this.failedRequests >= 3) { this.s.status = 'paused'; break; }
                        await new Promise(res => setTimeout(res, 2000));
                    }
                }
            },
        }" x-init="run()">
        <x-card>
            <div class="mb-2 flex items-center justify-between text-sm">
                <span class="font-medium text-gray-800" x-text="{ pending: @js(__('Starting…')), running: @js(__('Importing…')), done: @js(__('Finished')), failed: @js(__('Failed')), paused: @js(__('Paused: connection problem')) }[s.status]"></span>
                <span class="tabular-nums text-gray-500"><span x-text="s.rows_done"></span> / <span x-text="s.rows_total"></span></span>
            </div>
            <div class="h-2.5 overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-primary-dark transition-all" :style="`width: ${pct}%`"></div>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                <div><p class="text-xl font-semibold tabular-nums text-gray-800" x-text="s.created"></p><p class="text-xs text-gray-500">{{ __('new') }}</p></div>
                <div><p class="text-xl font-semibold tabular-nums text-gray-800" x-text="s.updated"></p><p class="text-xs text-gray-500">{{ __('updated') }}</p></div>
                <div><p class="text-xl font-semibold tabular-nums" :class="s.skipped ? 'text-amber-700' : 'text-gray-800'" x-text="s.skipped"></p><p class="text-xs text-gray-500">{{ __('skipped') }}</p></div>
            </div>
            <p x-show="s.status === 'running' || s.status === 'pending'" class="mt-4 text-xs text-gray-500">{{ __('Keep this page open until it finishes.') }}</p>
            <button type="button" x-show="s.status === 'paused'" @click="s.status = 'running'; run()" class="mt-4 text-sm font-medium text-primary hover:underline">{{ __('Resume') }}</button>
            <a x-show="s.status === 'done'" href="{{ route('products.index') }}" class="mt-4 inline-block text-sm font-medium text-primary hover:underline">{{ __('Go to products') }}</a>
        </x-card>

        <div x-show="s.errors.length" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <p class="mb-2 text-sm font-medium text-amber-900">{{ __('Rows that were skipped') }}</p>
            <ul class="space-y-1 text-xs text-amber-900"><template x-for="e in s.errors" :key="e"><li x-text="e"></li></template></ul>
        </div>
    </div>
</x-layouts.app>
