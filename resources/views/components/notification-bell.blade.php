{{-- Top-bar bell. Polls a one-query count endpoint (no websockets on shared
     hosting); the list is fetched only when the dropdown opens. --}}
<div class="relative"
    x-data="{
        open: false, unread: 0, urgent: 0, items: [], filter: 'all', loading: false,
        async poll() {
            try {
                const r = await fetch(@js(route('notifications.count')), { headers: { Accept: 'application/json' } });
                if (r.ok) { const d = await r.json(); this.unread = d.unread; this.urgent = d.urgent; }
            } catch (e) {}
        },
        async load() {
            this.loading = true;
            try {
                const r = await fetch(@js(route('notifications.feed')) + '?filter=' + this.filter, { headers: { Accept: 'application/json' } });
                if (r.ok) this.items = await r.json();
            } catch (e) {}
            this.loading = false;
        },
        toggle() { this.open = !this.open; if (this.open) this.load(); },
        async readAll() {
            await fetch(@js(route('notifications.read-all')), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
            this.unread = 0; this.items = this.items.map(i => ({ ...i, unread: false }));
        },
    }"
    x-init="poll(); setInterval(() => { if (!document.hidden) poll() }, {{ (int) config('notifications.poll_seconds', 45) * 1000 }})"
    @click.outside="open = false">
    <button type="button" @click="toggle()" class="relative flex h-9 w-9 items-center justify-center rounded-full border border-gray-200 text-gray-500 hover:bg-gray-100" aria-label="{{ __('Notifications') }}">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
        <span x-show="unread > 0" x-cloak x-text="unread > 99 ? '99+' : unread"
            :class="urgent > 0 ? 'bg-red-600' : 'bg-green-900'"
            class="absolute -right-1 -top-1 min-w-[18px] rounded-full px-1 text-center text-[10px] font-semibold leading-[18px] text-white"></span>
    </button>

    {{-- Phone: pinned to the screen edges under the top bar. sm+: drops from the bell. --}}
    <div x-show="open" x-cloak class="fixed inset-x-3 top-16 z-50 rounded-xl border border-gray-200 bg-white shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-[22rem]">
        <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2">
            <div class="flex gap-1 text-xs">
                @foreach (['all' => __('All'), 'unread' => __('Unread'), 'urgent' => __('Urgent')] as $key => $label)
                    <button type="button" @click="filter = @js($key); load()" class="rounded-full px-2.5 py-1"
                        :class="filter === @js($key) ? 'bg-green-900 text-white' : 'text-gray-600 hover:bg-gray-100'">{{ $label }}</button>
                @endforeach
            </div>
            <button type="button" @click="readAll()" x-show="unread > 0" class="text-xs text-green-900 hover:underline">{{ __('Mark all read') }}</button>
        </div>

        <div class="max-h-96 overflow-y-auto">
            <p x-show="loading && !items.length" class="p-4 text-center text-sm text-gray-400">{{ __('Loading…') }}</p>
            <p x-show="!loading && !items.length" class="p-6 text-center text-sm text-gray-400">{{ __('Nothing here.') }}</p>
            <template x-for="item in items" :key="item.id">
                <a :href="item.url" class="block border-b border-gray-50 px-3 py-2.5 hover:bg-gray-50"
                    :class="item.urgent ? 'bg-red-50' : (item.unread ? 'bg-green-50/40' : '')">
                    <div class="flex items-start gap-2">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.urgent ? 'bg-red-600' : (item.unread ? 'bg-green-800' : 'bg-transparent')"></span>
                        <div class="min-w-0">
                            <p class="text-sm text-gray-800" :class="item.unread && 'font-medium'" x-text="item.title"></p>
                            <p x-show="item.body" class="truncate text-xs text-gray-500" x-text="item.body"></p>
                            <p class="text-[11px] text-gray-400" x-text="item.ago"></p>
                        </div>
                    </div>
                </a>
            </template>
        </div>

        <a href="{{ route('notifications.index') }}" class="block border-t border-gray-100 px-3 py-2 text-center text-xs text-green-900 hover:bg-gray-50">{{ __('See all notifications') }}</a>
    </div>
</div>
