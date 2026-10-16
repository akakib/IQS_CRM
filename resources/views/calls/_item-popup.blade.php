{{-- Details of one number (managers): where it stands, the order taken, and every call with its recording. Opened by openItem(id). --}}
<div x-show="item.open" x-cloak @keydown.escape.window="item.open = false"
    class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4">
    <div class="absolute inset-0 bg-black/40" @click="item.open = false"></div>
    <div class="relative max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl" role="dialog" aria-modal="true" x-transition>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate text-base font-semibold text-gray-900" x-text="item.d.name || @js(__('Loading...'))"></p>
                <p class="font-mono text-sm text-gray-500" x-text="item.d.phone"></p>
            </div>
            <button type="button" @click="item.open = false" class="-mr-1 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="{{ __('Close') }}">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 5.3a1 1 0 011.4 0L10 7.6l2.3-2.3a1 1 0 111.4 1.4L11.4 9l2.3 2.3a1 1 0 01-1.4 1.4L10 10.4l-2.3 2.3a1 1 0 01-1.4-1.4L8.6 9 6.3 6.7a1 1 0 010-1.4z"/></svg>
            </button>
        </div>

        <p x-show="item.loading" class="mt-4 text-sm text-gray-500">{{ __('Loading...') }}</p>
        <p x-show="item.error" x-cloak class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="item.error"></p>

        <template x-if="! item.loading && item.d.name">
            <div class="mt-4 space-y-4">
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('State') }}</p>
                        <p class="font-medium text-gray-900" x-text="item.d.state"></p>
                        <p class="text-xs text-gray-500" x-show="item.d.holder" x-text="@js(__('With')) + ' ' + item.d.holder"></p>
                        <p class="text-xs text-gray-500" x-show="item.d.next_at" x-text="@js(__('Call again')) + ' ' + item.d.next_at"></p></div>
                    <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Result') }}</p>
                        <p class="font-medium text-gray-900" x-text="item.d.outcome || '-'"></p>
                        <p class="text-xs text-gray-500" x-text="item.d.attempts + ' ' + (item.d.attempts === 1 ? @js(__('try')) : @js(__('tries')))"></p></div>
                    <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Satisfied') }}</p>
                        <p class="font-medium" :class="! item.d.satisfaction ? 'text-gray-900' : (item.d.satisfaction.value < 50 ? 'text-red-700' : (item.d.satisfaction.value === 50 ? 'text-amber-700' : 'text-green-700'))"
                            x-text="item.d.satisfaction ? item.d.satisfaction.value + '% · ' + item.d.satisfaction.label : '-'"></p></div>
                    <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Order on the call') }}</p>
                        <template x-if="item.d.order"><p><a :href="item.d.order.url" class="font-medium text-green-700" :class="item.d.order.url && 'hover:underline'" x-text="item.d.order.no"></a>
                            <span class="block text-xs text-gray-500" x-text="'৳' + item.d.order.total"></span></p></template>
                        <p x-show="! item.d.order" class="font-medium text-gray-900">-</p></div>
                </div>

                <div x-show="item.d.topics.length" class="flex flex-wrap gap-1.5">
                    <template x-for="t in item.d.topics" :key="t.label">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="t.good ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'" x-text="(t.good ? '+ ' : '- ') + t.label"></span>
                    </template>
                </div>
                <p x-show="item.d.note" class="rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-800" x-text="item.d.note"></p>

                <div>
                    <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('Calls') }}</p>
                    <p x-show="! item.d.calls.length" class="text-sm text-gray-500">{{ __('Not called yet.') }}</p>
                    <template x-for="c in item.d.calls" :key="c.try">
                        <div class="border-t border-gray-100 py-2.5 text-sm first:border-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium text-gray-900" x-text="@js(__('Try')) + ' ' + c.try + ' · ' + (c.by || '-')"></span>
                                <span class="text-xs text-gray-500" x-text="c.when + (c.duration ? ' · ' + c.duration : '')"></span>
                            </div>
                            <p class="mt-0.5 text-gray-700" x-show="c.note" x-text="c.note"></p>
                            <template x-if="c.proof">
                                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                    <button type="button" @click="window.dispatchEvent(new CustomEvent('play-call', { detail: c.proof }))"
                                        class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary-soft px-2.5 py-0.5 text-xs font-medium text-primary hover:bg-primary hover:text-white">
                                        <svg x-show="c.proof.kind !== 'image'" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4l10 6-10 6V4z"/></svg>
                                        <span x-text="c.proof.kind === 'image' ? @js(__('View screenshot')) : @js(__('Play call'))"></span>
                                    </button>
                                    <span x-show="c.proof.old" class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">{{ __('Earlier file') }}</span>
                                    <span x-show="c.proof.picked" class="min-w-0 truncate text-xs text-gray-400" x-text="c.proof.picked"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <a x-show="item.d.journey" :href="item.d.journey" class="inline-block text-sm text-primary hover:underline">{{ __('Open customer') }}</a>
            </div>
        </template>
    </div>
</div>
