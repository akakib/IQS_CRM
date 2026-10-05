@php
    use Illuminate\Support\Carbon;
    $time = fn ($t) => $t ? Carbon::parse($t)->format('g:i A') : '-';
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
@endphp

<x-layouts.app :heading="__('Attendance & breaks')">
    <x-tabs :tabs="['day' => [__('Day'), route('attendance.index')], 'month' => [__('Month'), route('attendance.index', ['month' => substr($date, 0, 7)])]]" active="day" />

    <form method="GET" action="{{ route('attendance.index') }}" x-ref="f" class="mb-4 flex flex-wrap items-center gap-2">
        <x-date-input name="date" :value="$date" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.f.requestSubmit())" />
        @if ($date !== today()->toDateString())
            <a href="{{ route('attendance.index') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Today') }}</a>
        @endif
    </form>

    @if ($users->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('Nobody used the system on this day.') }}</div>
    @endif

    <div class="space-y-3">
        @foreach ($users as $u)
            @php
                $w = $work[$u->id] ?? null;
                $list = $breaks[$u->id] ?? collect();
                $rest = (int) $list->where('counts_as_break', true)->sum(fn ($b) => $b->ended_at ? (int) $b->minutes : (int) Carbon::parse($b->started_at)->diffInMinutes(now(), true));
                $away = (int) $list->where('counts_as_break', false)->sum(fn ($b) => $b->ended_at ? (int) $b->minutes : (int) Carbon::parse($b->started_at)->diffInMinutes(now(), true));
                $over = $limit > 0 && $rest > $limit;
                $shift = $shifts[$u->id] ?? null;
                $onScreen = $w && $w->last_seen_at ? (int) Carbon::parse($w->first_seen_at)->diffInMinutes(Carbon::parse($w->last_seen_at), true) : 0;
            @endphp
            <div @class(['rounded-xl border bg-white p-4', 'border-red-300' => $over, 'border-gray-200' => ! $over])>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium text-gray-800">{{ $u->name }} <span class="font-mono text-xs text-gray-400">#{{ $u->id }}</span></p>
                        <p class="text-xs text-gray-500">
                            {{ $shift ? __('Shift :a to :b', ['a' => $shift[0]->format('g:i A'), 'b' => $shift[1]->format('g:i A')]) : __('Off day') }}
                            @if ($w) · {{ __('in :a, last seen :b', ['a' => $time($w->first_seen_at), 'b' => $time($w->last_seen_at)]) }} · {{ __('worked about :t', ['t' => $hm(max(0, $onScreen - $rest))]) }} @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($w && $w->is_extra)
                            <x-badge :color="$w->extra_approved_at ? 'green' : 'amber'">{{ $w->extra_approved_at ? __('Extra day, approved') : __('Extra day, not approved') }}</x-badge>
                            @if ($canEdit)
                                <form method="POST" action="{{ route('attendance.extra', $w->id) }}">@csrf<button class="text-xs font-medium text-primary hover:underline">{{ $w->extra_approved_at ? __('Remove approval') : __('Approve') }}</button></form>
                            @endif
                        @endif
                        <x-badge :color="$over ? 'red' : 'gray'">{{ __('Breaks :u of :l min', ['u' => $rest, 'l' => $limit]) }}</x-badge>
                        @if ($away)<x-badge color="blue">{{ __('Away on work :t', ['t' => $hm($away)]) }}</x-badge>@endif
                    </div>
                </div>

                @if ($list->isNotEmpty())
                    <div class="mt-3 divide-y divide-gray-100 border-t border-gray-100">
                        @foreach ($list as $b)
                            <div class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" x-data="{ edit: false }">
                                <span class="text-gray-700">
                                    <b>{{ __($b->reason ?? 'Break') }}</b> · {{ $time($b->started_at) }} → {{ $b->ended_at ? $time($b->ended_at) : __('still out') }}
                                    @if ($b->ended_at) · {{ $hm((int) $b->minutes) }} @endif
                                    @unless ($b->counts_as_break)<span class="text-xs text-blue-700">({{ __('work, not counted') }})</span>@endunless
                                    @if ($b->auto_closed)<x-badge color="red">{{ __('Did not come back') }}</x-badge>@endif
                                    @if ($b->correction_note)<span class="text-xs text-gray-400">· {{ __('corrected: :n', ['n' => $b->correction_note]) }}</span>@endif
                                </span>
                                @if ($canEdit && $b->ended_at)
                                    <button type="button" @click="edit = !edit" class="text-xs font-medium text-primary hover:underline">{{ __('Correct') }}</button>
                                    <form x-show="edit" x-cloak method="POST" action="{{ route('attendance.breaks.correct', $b->id) }}" class="flex w-full flex-wrap items-center gap-2">
                                        @csrf
                                        <label class="text-xs text-gray-500">{{ __('Really ended at') }}
                                            <input type="time" name="ended_at" required value="{{ Carbon::parse($b->ended_at)->format('H:i') }}" class="ml-1 rounded-lg border border-gray-300 px-2 py-1 text-sm"></label>
                                        <input name="note" required maxlength="255" placeholder="{{ __('Why (required)') }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                        <x-button size="sm">{{ __('Save') }}</x-button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-layouts.app>
