<x-layouts.app :heading="__('Edit staff')">
    <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
        <a href="{{ route('users.index') }}" class="text-primary hover:underline">{{ __('All staff') }}</a>
        @can('access.manage')<a href="{{ route('users.access', $user) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50">{{ __('Access and roles') }}</a>@endcan
    </div>
    <form method="POST" action="{{ route('users.update', $user) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('users._form')
    </form>

    {{-- Office days: timers, breaks and extra-day records follow this. --}}
    @php
        $own = \Illuminate\Support\Facades\DB::table('work_schedules')->where('user_id', $user->id)->get()->keyBy('weekday');
        $dayNames = [6 => __('Saturday'), 0 => __('Sunday'), 1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday')];
    @endphp
    <x-card :title="__('Office days and hours')" :subtitle="$own->isEmpty() ? __('Using the office default (Settings > Working hours). Tick days here to give this person their own.') : __('This person has their own days. Untick all and save to go back to the office default.')" class="mt-6 max-w-2xl">
        <form method="POST" action="{{ route('users.schedule', $user) }}" class="space-y-2">
            @csrf
            @foreach ($dayNames as $n => $name)
                @php $row = $own[$n] ?? null; @endphp
                <div class="flex items-center gap-3" x-data="{ on: {{ $row ? 'true' : 'false' }} }">
                    <label class="flex w-36 items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="days[{{ $n }}][on]" value="1" x-model="on" class="rounded border-gray-300 text-primary"> {{ $name }}
                    </label>
                    <input type="time" name="days[{{ $n }}][start]" value="{{ $row ? substr($row->start_time, 0, 5) : settings('work.start') }}" :disabled="!on" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50 disabled:text-gray-400">
                    <span class="text-gray-400">&rarr;</span>
                    <input type="time" name="days[{{ $n }}][end]" value="{{ $row ? substr($row->end_time, 0, 5) : settings('work.end') }}" :disabled="!on" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-50 disabled:text-gray-400">
                    <span x-show="!on" class="text-xs text-gray-400">{{ __('Off') }}</span>
                </div>
            @endforeach
            <x-button size="sm" class="mt-2">{{ __('Save office days') }}</x-button>
        </form>
    </x-card>
</x-layouts.app>
