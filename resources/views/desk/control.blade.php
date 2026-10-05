@php
    use Illuminate\Support\Carbon;
    $ago = fn ($t) => $t ? Carbon::parse($t)->diffForHumans(now(), ['short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]) : '-';
    $order = ['new', 'record_verified', 'no_answer', 'hold', 'confirmed', 'ready_for_packaging', 'packed', 'ready_for_pickup', 'handed_over', 'in_transit'];
    $byKey = collect($statuses)->keyBy('key');
@endphp

<x-layouts.app :heading="__('Control room')">
<div x-data="{
        open: false, title: '', html: '', loading: false,
        async show(title, params) { this.title = title; this.open = true; await this.load(@js(route('desk.control.list')) + '?' + new URLSearchParams(params)) },
        async load(url) {
            this.loading = true;
            try { const r = await fetch(url, { headers: { Accept: 'text/html' } }); this.html = r.ok ? await r.text() : @js('<p class=\'py-10 text-center text-sm text-red-600\'>'.__('Could not load. Try again.').'</p>') } catch (e) {}
            this.loading = false;
        },
        page(e) { const a = e.target.closest('[data-pages] a[href]'); if (a) { e.preventDefault(); this.load(a.href) } },
    }">
    <p class="mb-4 text-sm text-gray-500">{{ __('Who holds what and where orders are stuck. Tap any number to see those orders. Reload for the latest numbers.') }}</p>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <button type="button" class="text-left" @click="show(@js(__('Waiting, nobody took')), { box: 'waiting' })">
    <x-stat-tile :label="__('Waiting, nobody took')" :value="(int) $age['total']" :hint="$age['total'] ? __('oldest :t', ['t' => $ago($age['oldest'])]) : null" :trend="$age['old'] ? 'down' : null" />
        </button>
        <button type="button" class="text-left" @click="show(@js(__('Waiting under 5 minutes')), { box: 'fresh' })">
    <x-stat-tile :label="__('Under 5 minutes')" :value="(int) $age['fresh']" />
        </button>
        <button type="button" class="text-left" @click="show(@js(__('Waiting 5 to 15 minutes')), { box: 'mid' })">
    <x-stat-tile :label="__('5 to 15 minutes')" :value="(int) $age['mid']" />
        </button>
        <button type="button" class="text-left" @click="show(@js(__('Waiting over 15 minutes')), { box: 'old' })">
    <x-stat-tile :label="__('Over 15 minutes')" :value="(int) $age['old']" :trend="$age['old'] ? 'down' : null" :hint="$age['old'] ? __('auto-assign should have taken these') : null" />
        </button>
    </div>

    <x-card :title="__('Orders by stage')" class="mb-6">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($order as $key)
                @php $st = $byKey[$key] ?? null; @endphp
                @continue(! $st)
                @php $row = $stages[$st['id']] ?? null; @endphp
                <button type="button" @click="show(@js(__($st['name'])), { box: 'stage', status: {{ $st['id'] }} })" class="rounded-lg border border-gray-200 p-3 text-left hover:border-primary">
                    <p class="text-xs font-medium" style="color: {{ $st['color'] }}">{{ __($st['name']) }}</p>
                    <p class="text-xl font-semibold tabular-nums text-gray-900">{{ (int) ($row->n ?? 0) }}</p>
                    <p class="text-[11px] text-gray-500">{{ $row ? __('oldest untouched :t', ['t' => $ago($row->oldest)]) : '' }}</p>
                </button>
            @endforeach
        </div>
    </x-card>

    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Moderators today') }}</h2>
    @if ($people->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('Nobody has been in today.') }}</div>
    @else
        @php
            $rows = $people->map(function ($p) use ($load, $released, $breaks, $activeWindow, $breakLimit) {
                $l = $load[$p->id] ?? null;
                $b = $breaks[$p->id] ?? null;

                return (object) [
                    'id' => $p->id,
                    'name' => $p->name,
                    'state' => $p->current_break_id ? 'break' : ($p->last_seen_at && $p->last_seen_at->gte(now()->subMinutes($activeWindow)) ? 'active' : 'away'),
                    'seen' => $p->last_seen_at,
                    'active' => (int) ($l->active ?? 0), 'no_response' => (int) ($l->no_response ?? 0), 'on_hold' => (int) ($l->on_hold ?? 0), 'to_send' => (int) ($l->to_send ?? 0),
                    'oldest' => $l->oldest ?? null, 'released' => (int) ($released[$p->id] ?? 0),
                    'break_minutes' => (int) ($b->minutes ?? 0), 'breaks' => (int) ($b->n ?? 0), 'over' => $breakLimit > 0 && (int) ($b->minutes ?? 0) > $breakLimit,
                ];
            });
            $stateBadge = ['active' => ['green', __('Active')], 'break' => ['amber', __('On break')], 'away' => ['gray', __('Away')]];
        @endphp
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Person') }}</th><th>{{ __('Now') }}</th><th class="text-right">{{ __('Holding') }}</th><th class="text-right">{{ __('Oldest') }}</th>
                <th class="text-right">{{ __('No response') }}</th><th class="text-right">{{ __('On hold') }}</th><th class="text-right">{{ __('To send') }}</th>
                <th class="text-right">{{ __('Timed out today') }}</th><th class="text-right">{{ __('Breaks today') }}</th>
            </x-slot:head>
            @foreach ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r->name }}</td>
                    <td><x-badge :color="$stateBadge[$r->state][0]">{{ $stateBadge[$r->state][1] }}</x-badge>
                        @if ($r->state === 'away' && $r->seen)<span class="ml-1 text-xs text-gray-400">{{ $r->seen->format('g:i A') }}</span>@endif</td>
                    <td class="text-right"><button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('Holding')), { box: 'holding', user: {{ $r->id }} })">{{ $r->active }}</button></td>
                    <td class="text-right tabular-nums {{ $r->oldest && Carbon::parse($r->oldest)->lt(now()->subMinutes(30)) ? 'text-red-600' : '' }}">{{ $ago($r->oldest) }}</td>
                    <td class="text-right"><button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('No response')), { box: 'no_response', user: {{ $r->id }} })">{{ $r->no_response }}</button></td>
                    <td class="text-right"><button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('On hold')), { box: 'on_hold', user: {{ $r->id }} })">{{ $r->on_hold }}</button></td>
                    <td class="text-right"><button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('To send')), { box: 'to_send', user: {{ $r->id }} })">{{ $r->to_send }}</button></td>
                    <td class="text-right {{ $r->released ? 'font-semibold text-red-600' : '' }}"><button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('Timed out today')), { box: 'timed_out', user: {{ $r->id }} })">{{ $r->released }}</button></td>
                    <td class="text-right {{ $r->over ? 'font-semibold text-red-600' : '' }}"><button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('Breaks today')), { box: 'breaks', user: {{ $r->id }} })">{{ $r->break_minutes }} {{ __('min') }} <span class="text-xs text-gray-400">({{ $r->breaks }})</span></button></td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards>
            @foreach ($rows as $r)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-gray-800">{{ $r->name }}</p>
                        <x-badge :color="$stateBadge[$r->state][0]">{{ $stateBadge[$r->state][1] }}</x-badge>
                    </div>
                    <div class="mt-2 grid grid-cols-3 gap-2 text-xs text-gray-600">
                        <span>{{ __('Holding') }}: <button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('Holding')), { box: 'holding', user: {{ $r->id }} })"><b>{{ $r->active }}</b></button></span>
                        <span>{{ __('Oldest') }}: <b>{{ $ago($r->oldest) }}</b></span>
                        <span>{{ __('No response') }}: <button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('No response')), { box: 'no_response', user: {{ $r->id }} })"><b>{{ $r->no_response }}</b></button></span>
                        <span>{{ __('On hold') }}: <button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('On hold')), { box: 'on_hold', user: {{ $r->id }} })"><b>{{ $r->on_hold }}</b></button></span>
                        <span class="{{ $r->released ? 'text-red-600' : '' }}">{{ __('Timed out') }}: <button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('Timed out today')), { box: 'timed_out', user: {{ $r->id }} })"><b>{{ $r->released }}</b></button></span>
                        <span class="{{ $r->over ? 'text-red-600' : '' }}">{{ __('Breaks') }}: <button type="button" class="tabular-nums underline decoration-dotted underline-offset-4 hover:text-primary" @click="show(@js($r->name.' · '.__('Breaks today')), { box: 'breaks', user: {{ $r->id }} })"><b>{{ $r->break_minutes }}m</b></button></span>
                    </div>
                </div>
            @endforeach
        </x-list.cards>
    @endif

    @can('attendance.view')
        <p class="mt-4 text-sm"><a href="{{ route('attendance.index') }}" class="font-medium text-primary hover:underline">{{ __('Attendance and breaks') }} &rarr;</a></p>
    @endcan

    {{-- The popup: closes only with Close. --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[120] flex items-end justify-center bg-black/50 sm:items-center sm:p-6" role="dialog" aria-modal="true">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3">
                    <h3 class="text-base font-semibold text-gray-900" x-text="title"></h3>
                    <button type="button" @click="open = false; html = ''" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Close') }}</button>
                </div>
                <div class="min-h-[160px] overflow-y-auto p-5" :class="loading && 'opacity-50'" @click="page($event)" x-html="html"></div>
            </div>
        </div>
    </template>
</div>
</x-layouts.app>
