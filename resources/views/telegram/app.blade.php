<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ settings('store.name') }}</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 p-4 font-sans text-gray-900">
    {{-- Telegram Mini App for packers: the batch pick list with "not found" reports. No customer details. --}}
    <div x-data="{
            state: 'loading', error: '', data: null, done: {},
            init_data() { return window.Telegram?.WebApp?.initData || '' },
            async post(url, body) {
                const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ ...body, init_data: this.init_data() }) });
                if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || r.status);
                return r.json();
            },
            async init() {
                window.Telegram?.WebApp?.ready();
                try { this.data = await this.post(@js(route('tg.app.data')), { batch: {{ (int) $batch }} }); this.state = 'ready'; }
                catch (e) { this.state = 'error'; this.error = e.message; }
            },
            async missing(line) {
                await this.post(@js(route('tg.app.report')), { batch: {{ (int) $batch }}, variant_id: line.variant_id });
                this.done[line.variant_id] = true;
            },
        }">
        <p x-show="state === 'loading'" class="text-sm text-gray-500">{{ __('Loading…') }}</p>
        <p x-show="state === 'error'" x-cloak class="rounded-lg bg-red-50 p-3 text-sm text-red-700" x-text="error"></p>
        <template x-if="state === 'ready'">
            <div>
                <p class="mb-1 text-lg font-semibold" x-text="data.batch"></p>
                <p class="mb-3 text-xs text-gray-500" x-text="data.name"></p>
                <template x-for="l in data.lines" :key="l.variant_id">
                    <div class="mb-2 flex items-center justify-between gap-2 rounded-xl bg-white p-3 shadow-sm">
                        <div><p class="text-sm font-medium" x-text="l.name"></p><p class="text-xs text-gray-500" x-text="(l.shelf ? l.shelf + ' · ' : '') + l.qty"></p></div>
                        <button type="button" @click="missing(l)" :disabled="done[l.variant_id]" class="shrink-0 rounded-lg px-3 py-2 text-xs font-medium"
                            :class="done[l.variant_id] ? 'bg-gray-100 text-gray-400' : 'bg-red-600 text-white'" x-text="done[l.variant_id] ? @js(__('Reported')) : @js(__('Not found'))"></button>
                    </div>
                </template>
            </div>
        </template>
    </div>
</body>
</html>
