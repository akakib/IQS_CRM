@php
    use App\Services\Calls\CallListService;
    $p = $progress;
    $o = $overview;
    $f = $filters;
    $pct = $p['total'] ? round($p['done'] / $p['total'] * 100) : 0;
    // The date range goes with every link, so a number opened from the Overview is the same period.
    $range = array_filter(['from' => $f['from'], 'to' => $f['to']]);
    $num = fn (array $q = []) => route('calls.lists.show', [$list->id] + $range + $q + ['clear' => 1]).'#numbers';
    $tile = 'block rounded-xl border border-gray-200 bg-white p-3 hover:border-primary hover:bg-primary-soft';
@endphp

<x-layouts.app :heading="$list->name">
    <div class="mx-auto max-w-6xl space-y-4" x-data="{
            item: { open: false, loading: false, error: null, d: {} },
            async openItem(id) {
                this.item = { open: true, loading: true, error: null, d: {} };
                try {
                    const r = await fetch(@js(route('calls.lists.item', [$list->id, 0])).replace(/\/0$/, '/' + id), { headers: { Accept: 'application/json' } });
                    if (! r.ok) throw new Error();
                    this.item.d = await r.json();
                } catch (e) { this.item.error = @js(__('Could not open it. Check the connection and try again.')) }
                this.item.loading = false;
            },
        }">
        <a href="{{ route('calls.lists') }}" class="inline-block text-sm text-primary hover:underline">{{ __('Back to call lists') }}</a>

        {{-- The whole list, whatever the dates. --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <p class="text-xs text-gray-500">{{ __(CallListService::PURPOSES[$list->purpose] ?? $list->purpose) }} · {{ $list->file_name ?? '-' }}@unless ($list->is_active) · <span class="font-medium text-amber-700">{{ __('Paused') }}</span>@endunless</p>
            <div class="mt-2 flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <span><span class="text-lg font-semibold tabular-nums">{{ number_format($p['done']) }}</span> <span class="text-gray-500">{{ __('of :n numbers done', ['n' => number_format($p['total'])]) }}</span></span>
                <span class="text-xs text-gray-500">{{ __(':w waiting · :l call again later · :h with a caller now', ['w' => number_format($p['waiting']), 'l' => number_format($p['later']), 'h' => number_format($p['in_hand'])]) }}</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-primary" style="width: {{ $pct }}%"></div></div>
        </div>

        <div x-data="tabs(@js($errors->any() ? 'settings' : 'overview'))">
            <x-tabs :tabs="['overview' => [__('Overview'), '#overview'], 'numbers' => [__('Numbers'), '#numbers', number_format($numbers->total())], 'settings' => [__('Settings'), '#settings']]" active="overview" />

            {{-- One date range for Overview and Numbers; the open tab stays open. --}}
            <form method="GET" action="{{ route('calls.lists.show', $list->id) }}" x-show="tab !== 'settings'" class="mb-4 flex flex-wrap items-center gap-2"
                @submit="$el.action = $el.action.split('#')[0] + '#' + tab" x-on:select-change="$nextTick(() => $el.requestSubmit())">
                <input type="hidden" name="clear" value="1">
                @foreach (\Illuminate\Support\Arr::except($f, ['from', 'to']) as $k => $v)
                    @if ($v !== '' && ! ($k === 'sort' && $v === 'recent') && ! ($k === 'per' && $v === 25))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                @endforeach
                <span class="w-full text-sm text-gray-600 sm:w-auto">{{ __('Calls made') }}</span>
                <x-date-range :from="$f['from'] ?: null" :to="$f['to'] ?: null" />
                @if ($range)<a href="{{ route('calls.lists.show', [$list->id] + \Illuminate\Support\Arr::except(array_filter($f, fn ($v, $k) => $v !== '' && ! ($k === 'sort' && $v === 'recent') && ! ($k === 'per' && $v === 25), ARRAY_FILTER_USE_BOTH), ['from', 'to']) + ['clear' => 1]) }}" class="text-sm text-primary hover:underline" @click="$el.href = $el.href.split('#')[0] + '#' + tab">{{ __('All days') }}</a>@endif
            </form>

            {{-- Overview: how the calls in the period went. Every figure opens the matching numbers. --}}
            <section x-show="tab === 'overview'" class="space-y-4">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <a href="{{ $num() }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Calls') }}</p><p class="text-lg font-semibold tabular-nums">{{ number_format($o['calls']) }}</p><p class="text-xs text-gray-500">{{ trans_choice(':count customer|:count customers', $o['customers'], ['count' => number_format($o['customers'])]) }}</p></a>
                    <a href="{{ $num(['outcome' => 'answered']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Talked') }}</p><p class="text-lg font-semibold tabular-nums">{{ number_format($o['talked']) }}</p></a>
                    <a href="{{ $num(['outcome' => 'not_reached']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('No answer or off') }}</p><p class="text-lg font-semibold tabular-nums">{{ number_format($o['not_reached']) }}</p></a>
                    <a href="{{ $num(['outcome' => 'wrong']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Wrong number') }}</p><p class="text-lg font-semibold tabular-nums">{{ number_format($o['wrong']) }}</p></a>
                    <a href="{{ $num(['mood' => 'happy']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Happy') }}</p><p class="text-lg font-semibold tabular-nums text-green-700">{{ number_format($o['happy']) }}</p></a>
                    <a href="{{ $num(['mood' => 'okay']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Okay') }}</p><p class="text-lg font-semibold tabular-nums text-amber-700">{{ number_format($o['okay']) }}</p></a>
                    <a href="{{ $num(['mood' => 'unhappy']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Unhappy') }}</p><p class="text-lg font-semibold tabular-nums text-red-700">{{ number_format($o['unhappy']) }}</p></a>
                    <a href="{{ $num(['sort' => 'unhappy']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Satisfaction') }}</p><p class="text-lg font-semibold tabular-nums">{{ $o['satisfaction'] === null ? '-' : $o['satisfaction'].'%' }}</p></a>
                </div>
                <a href="{{ $num(['flag' => 'ordered']) }}" class="flex items-baseline justify-between gap-2 rounded-xl border border-green-200 bg-green-50 p-3 hover:border-primary">
                    <span class="text-sm text-gray-700">{{ __('Orders from these calls') }}</span>
                    <span><span class="text-lg font-semibold tabular-nums text-green-800">{{ number_format($o['orders']) }}</span> <span class="text-sm text-gray-600">৳{{ number_format($o['sold']) }}</span></span>
                </a>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
                        <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('What customers said about') }}</p>
                        @forelse ($o['topics'] as $t)
                            <div class="flex items-center justify-between gap-3 border-t border-gray-100 py-2 text-sm first:border-0">
                                <span class="text-gray-800">{{ $t['label'] }}</span>
                                <span class="flex gap-2 text-xs"><span class="rounded-full bg-red-50 px-2 py-0.5 font-medium text-red-700">{{ __(':n bad', ['n' => $t['bad']]) }}</span><span class="rounded-full bg-green-50 px-2 py-0.5 font-medium text-green-800">{{ __(':n good', ['n' => $t['good']]) }}</span></span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('Nothing said yet in this period.') }}</p>
                        @endforelse
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
                        <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('Callers') }}</p>
                        @forelse ($o['people'] as $person)
                            <a href="{{ $num(['caller' => $person->user_id]) }}" class="flex flex-wrap items-baseline justify-between gap-2 border-t border-gray-100 py-2 text-sm first:border-0 hover:text-primary">
                                <span class="font-medium text-gray-900">{{ $person->name }}</span>
                                <span class="text-xs text-gray-600">{{ __(':c calls · :t talked · :o orders', ['c' => $person->calls, 't' => $person->talked, 'o' => $person->orders]) }}@if ($person->sat !== null) · {{ round($person->sat) }}%@endif</span>
                            </a>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('No calls in this period.') }}</p>
                        @endforelse
                        @if ($p['people']->isNotEmpty())
                            <p class="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Today against the target') }}</p>
                            @foreach ($p['people'] as $person)
                                @php $w = $person->target ? min(100, round($person->today / $person->target * 100)) : 0; @endphp
                                <div class="flex items-center gap-3 py-1.5 text-sm">
                                    <span class="w-28 shrink-0 truncate text-gray-800">{{ $person->name }}</span>
                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full {{ $w >= 100 ? 'bg-green-600' : 'bg-primary' }}" style="width: {{ $w }}%"></div></div>
                                    <span class="w-14 shrink-0 text-right tabular-nums"><span class="font-semibold">{{ $person->today }}</span>/{{ $person->target }}</span>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </section>

            <section x-show="tab === 'numbers'" x-cloak>
                @include('calls._numbers')
            </section>

            <section x-show="tab === 'settings'" x-cloak class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
                <form method="POST" action="{{ route('calls.lists.update', $list->id) }}" class="space-y-4">
                    @csrf @method('PUT')
                    @include('calls._form', ['list' => $list, 'callers' => $callers, 'assigned' => $assigned])
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($list->is_active) class="h-4 w-4 rounded border-gray-300 text-primary">
                        {{ __('Active (callers see it)') }}
                    </label>
                    <x-button>{{ __('Save') }}</x-button>
                </form>
            </section>
        </div>

        @include('calls._item-popup')
    </div>
</x-layouts.app>
