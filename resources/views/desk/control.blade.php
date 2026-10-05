@php
    use Illuminate\Support\Carbon;
    $ago = fn ($t) => $t ? Carbon::parse($t)->diffForHumans(now(), ['short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]) : '-';
    $order = ['new', 'record_verified', 'no_answer', 'hold', 'confirmed', 'ready_for_packaging', 'packed', 'ready_for_pickup', 'handed_over', 'in_transit'];
    $byKey = collect($statuses)->keyBy('key');
@endphp

<x-layouts.app :heading="__('Control room')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Who holds what and where orders are stuck. Reload for the latest numbers.') }}</p>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-stat-tile :label="__('Waiting, nobody took')" :value="(int) $age['total']" :hint="$age['total'] ? __('oldest :t', ['t' => $ago($age['oldest'])]) : null" :trend="$age['old'] ? 'down' : null" />
        <x-stat-tile :label="__('Under 5 minutes')" :value="(int) $age['fresh']" />
        <x-stat-tile :label="__('5 to 15 minutes')" :value="(int) $age['mid']" />
        <x-stat-tile :label="__('Over 15 minutes')" :value="(int) $age['old']" :trend="$age['old'] ? 'down' : null" :hint="$age['old'] ? __('auto-assign should have taken these') : null" />
    </div>

    <x-card :title="__('Orders by stage')" class="mb-6">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($order as $key)
                @php $st = $byKey[$key] ?? null; @endphp
                @continue(! $st)
                @php $row = $stages[$st['id']] ?? null; @endphp
                <a href="{{ route('orders.index', ['tab' => 'all', 'status' => $st['id']]) }}" class="rounded-lg border border-gray-200 p-3 hover:border-green-800">
                    <p class="text-xs font-medium" style="color: {{ $st['color'] }}">{{ __($st['name']) }}</p>
                    <p class="text-xl font-semibold tabular-nums text-gray-900">{{ (int) ($row->n ?? 0) }}</p>
                    <p class="text-[11px] text-gray-500">{{ $row ? __('oldest untouched :t', ['t' => $ago($row->oldest)]) : '' }}</p>
                </a>
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
                    <td class="text-right tabular-nums">{{ $r->active }}</td>
                    <td class="text-right tabular-nums {{ $r->oldest && Carbon::parse($r->oldest)->lt(now()->subMinutes(30)) ? 'text-red-600' : '' }}">{{ $ago($r->oldest) }}</td>
                    <td class="text-right tabular-nums">{{ $r->no_response }}</td>
                    <td class="text-right tabular-nums">{{ $r->on_hold }}</td>
                    <td class="text-right tabular-nums">{{ $r->to_send }}</td>
                    <td class="text-right tabular-nums {{ $r->released ? 'font-semibold text-red-600' : '' }}">{{ $r->released }}</td>
                    <td class="text-right tabular-nums {{ $r->over ? 'font-semibold text-red-600' : '' }}">{{ $r->break_minutes }} {{ __('min') }} <span class="text-xs text-gray-400">({{ $r->breaks }})</span></td>
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
                        <span>{{ __('Holding') }}: <b>{{ $r->active }}</b></span>
                        <span>{{ __('Oldest') }}: <b>{{ $ago($r->oldest) }}</b></span>
                        <span>{{ __('No response') }}: <b>{{ $r->no_response }}</b></span>
                        <span>{{ __('On hold') }}: <b>{{ $r->on_hold }}</b></span>
                        <span class="{{ $r->released ? 'text-red-600' : '' }}">{{ __('Timed out') }}: <b>{{ $r->released }}</b></span>
                        <span class="{{ $r->over ? 'text-red-600' : '' }}">{{ __('Breaks') }}: <b>{{ $r->break_minutes }}m</b></span>
                    </div>
                </div>
            @endforeach
        </x-list.cards>
    @endif

    @can('attendance.view')
        <p class="mt-4 text-sm"><a href="{{ route('attendance.index') }}" class="font-medium text-green-900 hover:underline">{{ __('Attendance and breaks') }} &rarr;</a></p>
    @endcan
</x-layouts.app>
