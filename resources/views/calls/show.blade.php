@php
    use App\Services\Calls\CallListService;
    $p = $progress;
    $pct = $p['total'] ? round($p['done'] / $p['total'] * 100) : 0;
    $at = fn (array $q) => route('calls.lists.show', [$list->id] + $q).'#numbers';
    $tile = 'block rounded-xl bg-gray-50 p-3 hover:bg-primary-soft hover:ring-1 hover:ring-primary/30';
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

        <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <p class="text-xs text-gray-500">{{ __(CallListService::PURPOSES[$list->purpose] ?? $list->purpose) }} · {{ $list->file_name ?? '-' }}@unless ($list->is_active) · <span class="font-medium text-amber-700">{{ __('Paused') }}</span>@endunless</p>
            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <a href="{{ $at(['state' => 'done']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Done') }}</p><p class="text-lg font-semibold tabular-nums">{{ number_format($p['done']) }} <span class="text-sm font-normal text-gray-500">/ {{ number_format($p['total']) }}</span></p></a>
                <a href="{{ $at(['state' => 'later']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Call again later') }}</p><p class="text-lg font-semibold tabular-nums">{{ number_format($p['later']) }}</p></a>
                <a href="{{ $at(['sort' => 'unhappy']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Satisfaction') }}</p><p class="text-lg font-semibold tabular-nums">{{ $p['satisfaction'] === null ? '-' : $p['satisfaction'].'%' }}</p></a>
                <a href="{{ $at(['flag' => 'ordered']) }}" class="{{ $tile }}"><p class="text-xs text-gray-500">{{ __('Orders from calls') }}</p><p class="text-lg font-semibold tabular-nums">{{ $p['orders'] }} <span class="text-sm font-normal text-gray-500">৳{{ number_format($p['sold']) }}</span></p></a>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-primary" style="width: {{ $pct }}%"></div></div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('Callers today') }}</p>
            @forelse ($p['people'] as $person)
                @php $w = $person->target ? min(100, round($person->today / $person->target * 100)) : 0; @endphp
                <div class="flex items-center gap-3 border-t border-gray-100 py-2.5 text-sm first:border-0">
                    <span class="w-32 shrink-0 truncate text-gray-800">{{ $person->name }}</span>
                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full {{ $w >= 100 ? 'bg-green-600' : 'bg-primary' }}" style="width: {{ $w }}%"></div></div>
                    <span class="w-24 shrink-0 text-right tabular-nums"><span class="font-semibold">{{ $person->today }}</span>/{{ $person->target }} <span class="text-xs text-gray-400">· {{ $person->all }}</span></span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Nobody is on this list.') }}</p>
            @endforelse
            @if ($p['outcomes']->isNotEmpty())
                <p class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-600">
                    @foreach ($p['outcomes'] as $o => $n)<span>{{ __(CallListService::OUTCOMES[$o] ?? $o) }} <span class="font-medium text-gray-900">{{ $n }}</span></span>@endforeach
                </p>
            @endif
        </div>

        @if ($p['unhappy']->isNotEmpty())
            <div class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                <p class="mb-2 text-sm font-semibold text-red-800">{{ __('Unhappy customers') }}</p>
                @foreach ($p['unhappy'] as $u)
                    <div class="border-t border-gray-100 py-2.5 text-sm first:border-0">
                        <p><button type="button" @click="openItem({{ $u->id }})" class="font-medium text-gray-900 hover:text-primary hover:underline">{{ $u->name }}</button> <x-badge color="red">{{ $u->satisfaction }}%</x-badge>
                            @foreach (json_decode((string) $u->topics, true) ?: [] as $t => $v)@if ($v === 'bad')<span class="ml-1 text-xs text-red-700">{{ __(CallListService::TOPICS[$t] ?? $t) }}</span>@endif @endforeach
                            @if ($u->customer_id)<a href="{{ route('journey.show', $u->customer_id) }}" class="ml-1 text-xs text-primary hover:underline">{{ __('Journey') }}</a>@endif</p>
                        @if ($u->note)<p class="mt-0.5 text-gray-700">{{ $u->note }}</p>@endif
                        <p class="text-xs text-gray-500">{{ __('By :n', ['n' => $u->by ?? '-']) }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        @include('calls._numbers')

        <details class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5" @if ($errors->any()) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-gray-900">{{ __('Change callers, target or pause') }}</summary>
            <form method="POST" action="{{ route('calls.lists.update', $list->id) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                @include('calls._form', ['list' => $list, 'callers' => $callers, 'assigned' => $assigned])
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked($list->is_active) class="h-4 w-4 rounded border-gray-300 text-primary">
                    {{ __('Active (callers see it)') }}
                </label>
                <x-button>{{ __('Save') }}</x-button>
            </form>
        </details>

        @include('calls._item-popup')
    </div>
</x-layouts.app>
