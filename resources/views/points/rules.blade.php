@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    $recipients = ['order_moderator' => __('Assigned moderator'), 'actor' => __('Person who did it'), 'packer' => __('Packer'), 'previous_moderator' => __('Previously assigned moderator')];
    $settle = ['order_final' => __('When the order ends'), 'immediate' => __('Right away')];
    $fmt = fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? '-');
    $stages = collect($triggers)->groupBy(fn ($t) => $t[1], true);
@endphp

<x-layouts.app :heading="__('Points rules')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Points reward speed and fewer mistakes. They are separate from the KPI (order volume). A changed value applies to new points only; points already given keep the value they had.') }}</p>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @foreach ($stages as $stage => $stageTriggers)
                <section>
                    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __($stage) }}</h2>
                    <div class="space-y-2">
                        @foreach ($stageTriggers as $key => $t)
                            @foreach ($rules[$key] ?? [] as $r)
                                <div @class(['rounded-xl border border-gray-200 bg-white p-3', 'opacity-50' => ! $r->is_active])>
                                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-800">{{ $r->name }}</p>
                                            <p class="mt-1 text-xs text-gray-500">
                                                <span class="mr-1 inline-block rounded bg-gray-100 px-1.5 py-0.5">{{ __($t[0]) }}</span>
                                                @foreach ($conditions[$r->id] ?? [] as $c)
                                                    <span class="mr-1 inline-block rounded bg-gray-100 px-1.5 py-0.5">{{ $c->field }} {{ $c->operator }} {{ $c->value }}</span>
                                                @endforeach
                                                · {{ $recipients[$r->recipient] }} · {{ $settle[$r->settle_on] }}
                                                @if ($r->requires_delivery) · {{ __('only if delivered') }} @endif
                                            </p>
                                        </div>
                                        <form method="POST" action="{{ route('settings.points.update', $r->id) }}" class="flex shrink-0 items-center gap-2">
                                            @csrf @method('PUT')
                                            <input type="number" name="points" step="0.5" value="{{ (float) $r->points }}" required
                                                class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-right text-sm tabular-nums {{ $r->points < 0 ? 'text-red-700' : 'text-green-800' }}" aria-label="{{ __('Points') }}">
                                            <label class="flex items-center gap-1 text-xs text-gray-600">
                                                <input type="hidden" name="is_active" value="0">
                                                <input type="checkbox" name="is_active" value="1" @checked($r->is_active) class="rounded border-gray-300 text-green-900"> {{ __('On') }}
                                            </label>
                                            <x-button size="sm">{{ __('Save') }}</x-button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <div class="space-y-6">
            <x-card :title="__('Test on an order')" :subtitle="__('Which rules match this order now, and the points it already gave.')">
                <form method="POST" action="{{ route('settings.points.test') }}" class="flex gap-2">
                    @csrf
                    <input name="order_no" required placeholder="IQ10001" class="{{ $input }} font-mono">
                    <x-button size="sm">{{ __('Test') }}</x-button>
                </form>
                @if ($test)
                    <div class="mt-3 space-y-3 rounded-lg bg-gray-50 p-3 text-xs">
                        <p class="text-sm font-semibold">{{ $test['order_no'] }}</p>
                        @foreach ($test['preview'] as $trigger => $p)
                            <div>
                                <p class="font-medium text-gray-700">{{ __($triggers[$trigger][0] ?? $trigger) }}</p>
                                @foreach ($p['context'] as $k => $v)
                                    <div class="flex justify-between gap-2 border-b border-gray-100 py-0.5"><span class="text-gray-500">{{ __($fields[$k] ?? $k) }}</span><span class="font-mono">{{ $fmt($v) }}</span></div>
                                @endforeach
                                @forelse ($p['rules'] as $m)
                                    <p class="mt-1">{{ $m['name'] }}: <b @class(['text-green-800' => $m['points'] > 0, 'text-red-700' => $m['points'] < 0])>{{ $m['points'] > 0 ? '+' : '' }}{{ $m['points'] }}</b> ({{ $recipients[$m['recipient']] }})</p>
                                @empty
                                    <p class="mt-1 text-gray-400">{{ __('No rule matches.') }}</p>
                                @endforelse
                            </div>
                        @endforeach
                        <div>
                            <p class="font-medium text-gray-700">{{ __('Points given so far') }}</p>
                            @forelse ($test['ledger'] as $l)
                                <p>{{ $l['name'] }} · {{ $l['rule'] }}: <b>{{ $l['points'] > 0 ? '+' : '' }}{{ $l['points'] }}</b> <span class="text-gray-400">({{ $l['status'] }})</span></p>
                            @empty
                                <p class="text-gray-400">{{ __('None yet.') }}</p>
                            @endforelse
                        </div>
                    </div>
                @endif
            </x-card>

            <x-card :title="__('Add a rule')" x-data="{ conds: [] }">
                <form method="POST" action="{{ route('settings.points.store') }}" class="space-y-2">
                    @csrf
                    <x-simple-select name="trigger_key" :options="collect($triggers)->map(fn ($t) => __($t[0]))->all()" :value="array_key_first($triggers)" full-width class="w-full" />
                    <input name="name" required maxlength="150" placeholder="{{ __('Rule name') }}" class="{{ $input }}">
                    <input name="points" type="number" step="0.5" required placeholder="{{ __('Points, e.g. 2 or -3') }}" class="{{ $input }}">
                    <x-simple-select name="recipient" :options="$recipients" value="order_moderator" full-width class="w-full" />
                    <x-simple-select name="settle_on" :options="$settle" value="order_final" full-width class="w-full" />
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="hidden" name="requires_delivery" value="0">
                        <input type="checkbox" name="requires_delivery" value="1" class="rounded border-gray-300 text-green-900"> {{ __('Only if the order is delivered') }}
                    </label>
                    <template x-for="(c, i) in conds" :key="i">
                        <div class="rounded-lg border border-gray-200 p-2">
                            <div class="mb-1 flex flex-wrap gap-1">
                                @foreach ($fields as $key => $label)
                                    <button type="button" @click="c.field = @js($key)" class="rounded border px-1.5 py-0.5 text-[11px]" :class="c.field === @js($key) ? 'border-green-900 bg-green-900 text-white' : 'border-gray-200 text-gray-600'">{{ $key }}</button>
                                @endforeach
                            </div>
                            <input type="hidden" :name="`conditions[${i}][field]`" :value="c.field">
                            <div class="flex gap-1">
                                <input type="hidden" :name="`conditions[${i}][operator]`" :value="c.operator">
                                @foreach (['>=', '<=', '=', '!=', '>', '<'] as $op)
                                    <button type="button" @click="c.operator = @js($op)" class="w-8 rounded border text-xs" :class="c.operator === @js($op) ? 'border-green-900 bg-green-900 text-white' : 'border-gray-300'">{{ $op }}</button>
                                @endforeach
                                <input :name="`conditions[${i}][value]`" x-model="c.value" required placeholder="30 / true / sales" class="min-w-0 flex-1 rounded border border-gray-300 px-2 text-xs">
                                <button type="button" @click="conds.splice(i, 1)" class="px-1 text-gray-400 hover:text-red-600">&times;</button>
                            </div>
                        </div>
                    </template>
                    <button type="button" @click="conds.push({ field: 'blame', operator: '=', value: '' })" class="text-sm font-medium text-green-900 hover:underline">+ {{ __('Add condition') }}</button>
                    <x-button class="w-full">{{ __('Save rule') }}</x-button>
                </form>
            </x-card>
        </div>
    </div>
</x-layouts.app>
