@php
    $hasFilters = $filters['actor'] || $filters['type'] || $filters['q'] !== '' || $filters['from'] || $filters['to'];
    $show = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '-'));
    $inputClass = 'rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
@endphp

<x-layouts.app :heading="__('Activity log')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Every change to staff, roles, access and settings. Entries can never be edited or removed.') }}</p>

    <form method="GET" action="{{ route('activity.index') }}" x-ref="filters"
        x-data="{ timer: null, go() { clearTimeout(this.timer); this.timer = setTimeout(() => $refs.filters.requestSubmit(), 300) } }"
        @select-change="setTimeout(() => $refs.filters.requestSubmit(), 0)"
        class="mb-4 flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3 md:flex-row md:flex-wrap md:items-center">
        <input type="search" name="q" value="{{ $filters['q'] }}" @input="go()" placeholder="{{ __('Action, e.g. role.') }}" class="{{ $inputClass }} w-full md:max-w-[200px]">
        <div class="grid grid-cols-2 gap-2 md:flex">
            <x-simple-select name="actor" :options="['' => __('Anyone')] + $actorOptions" :value="(string) ($filters['actor'] ?? '')" full-width />
            <x-simple-select name="type" :options="['' => __('All records')] + $typeOptions" :value="$filters['type'] ?? ''" full-width />
        </div>
        <div class="grid grid-cols-2 gap-2 md:flex">
            <input type="date" name="from" value="{{ $filters['from'] }}" @change="$refs.filters.requestSubmit()" class="{{ $inputClass }}" aria-label="{{ __('From') }}">
            <input type="date" name="to" value="{{ $filters['to'] }}" @change="$refs.filters.requestSubmit()" class="{{ $inputClass }}" aria-label="{{ __('To') }}">
        </div>
        <div class="flex items-center gap-2 md:ml-auto">
            <x-simple-select name="per_page" :options="array_combine($perPageOptions, array_map(fn ($n) => __(':n / page', ['n' => $n]), $perPageOptions))" :value="$filters['per_page']" />
            @if ($hasFilters)
                <a href="{{ route('activity.index') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
        </div>
    </form>

    @if ($entries->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No activity found.') }}</div>
    @else
        <div class="space-y-2">
            @foreach ($entries as $e)
                @php($changes = collect(array_keys(($e->after ?? []) + ($e->before ?? []))))
                <div class="rounded-xl border border-gray-200 bg-white p-3" x-data="{ open: false }">
                    <button type="button" class="flex w-full flex-col gap-1 text-left md:flex-row md:items-center md:gap-4" @click="open = !open">
                        <span class="w-40 shrink-0 text-xs text-gray-400">{{ $e->created_at->format('d M Y, g:i:s A') }}</span>
                        <span class="w-32 shrink-0 text-sm font-medium text-gray-800">{{ $e->actor?->name ?? __('System') }}</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{{ $e->action }}</span>
                        <span class="text-sm text-gray-500">{{ $e->subject_type ? $e->subject_type.' #'.$e->subject_id : '' }}</span>
                        <span class="truncate text-xs text-gray-400 md:ml-auto">{{ $changes->take(4)->join(', ') }}{{ $changes->count() > 4 ? '…' : '' }}</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-3 overflow-x-auto border-t border-gray-100 pt-3">
                        <table class="w-full text-xs">
                            <thead class="text-left text-gray-400"><tr><th class="py-1 pr-4">{{ __('Field') }}</th><th class="py-1 pr-4">{{ __('Before') }}</th><th class="py-1">{{ __('After') }}</th></tr></thead>
                            <tbody>
                                @foreach ($changes as $field)
                                    <tr class="align-top">
                                        <td class="py-1 pr-4 font-medium text-gray-600">{{ $field }}</td>
                                        <td class="py-1 pr-4 text-red-700">{{ array_key_exists($field, $e->before ?? []) ? $show($e->before[$field]) : '' }}</td>
                                        <td class="py-1 text-green-800">{{ array_key_exists($field, $e->after ?? []) ? $show($e->after[$field]) : '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if ($e->ip)<p class="mt-2 text-xs text-gray-400">IP {{ $e->ip }}</p>@endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $entries->links() }}</div>
    @endif
</x-layouts.app>
