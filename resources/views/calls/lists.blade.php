@php use App\Services\Calls\CallListService; @endphp

<x-layouts.app :heading="__('Call lists')">
    <div class="mx-auto max-w-4xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">{{ __('Make a list from an imported file, give it to callers with a daily target, and follow it here.') }}</p>
            <x-button :href="route('calls.lists.create')">+ {{ __('New call list') }}</x-button>
        </div>

        @forelse ($lists as $l)
            @php $p = $l->progress; $pct = $p['total'] ? round($p['done'] / $p['total'] * 100) : 0; @endphp
            <a href="{{ route('calls.lists.show', $l->id) }}" class="block rounded-2xl border border-gray-200 bg-white p-4 hover:border-primary {{ $l->is_active ? '' : 'opacity-60' }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-gray-900">{{ $l->name }}</p>
                        <p class="text-xs text-gray-500">{{ __(CallListService::PURPOSES[$l->purpose] ?? $l->purpose) }} · {{ $l->file_name ?? '-' }} · {{ __(':n a day each', ['n' => $l->daily_target]) }}@unless ($l->is_active) · {{ __('Paused') }}@endunless</p>
                    </div>
                    <p class="text-right text-sm"><span class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format($p['done']) }}</span><span class="text-gray-500"> / {{ number_format($p['total']) }}</span></p>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-primary" style="width: {{ $pct }}%"></div></div>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                    @foreach ($p['people'] as $person)
                        <span>{{ $person->name }} <span class="font-medium {{ $person->today >= $person->target ? 'text-green-700' : 'text-gray-900' }}">{{ $person->today }}/{{ $person->target }}</span> {{ __('today') }}</span>
                    @endforeach
                    @if ($p['satisfaction'] !== null)<span>{{ __('Satisfaction :s%', ['s' => $p['satisfaction']]) }}</span>@endif
                    @if ($p['orders'])<span>{{ __(':n orders · ৳:a', ['n' => $p['orders'], 'a' => number_format($p['sold'])]) }}</span>@endif
                </div>
            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                <p class="text-sm font-medium text-gray-700">{{ __('No call list yet') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('Import a file under Customers first, then make a list from it.') }}</p>
            </div>
        @endforelse
    </div>
</x-layouts.app>
