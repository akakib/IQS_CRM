@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $typeLabels = ['cancel' => __('Cancel reasons'), 'hold' => __('Hold reasons'), 'amendment' => __('Order change reasons'), 'return' => __('Return reasons'), 'reassign' => __('Reassign reasons'), 'break' => __('Break reasons'), 'status' => __('Other')];
    $blame = ['none' => __('Nobody'), 'sales' => __('Sales / agent'), 'verification' => __('Verification'), 'packaging' => __('Packaging'), 'dispatch' => __('Dispatch'), 'courier' => __('Courier'), 'customer' => __('Customer')];
@endphp

<x-layouts.app :heading="__('Order statuses and reasons')">
    <div class="grid gap-6 xl:grid-cols-2">
        <x-card :title="__('Statuses')" :subtitle="__('Rename or recolour. What each status does is fixed by the system.')">
            @foreach ($statuses as $s)
                <form method="POST" action="{{ route('settings.reasons.status', $s->id) }}" class="mb-2 flex items-center gap-2">
                    @csrf
                    @method('PUT')
                    <input type="color" name="color" value="{{ $s->color }}" class="h-9 w-10 rounded border border-gray-300" aria-label="{{ __('Colour') }}">
                    <input name="name_en" value="{{ $s->name_en }}" maxlength="100" class="{{ $input }}">
                    <span class="hidden w-28 shrink-0 font-mono text-xs text-gray-400 md:block">{{ $s->system_key }}</span>
                    <x-button size="sm" variant="secondary">{{ __('Save') }}</x-button>
                </form>
            @endforeach
        </x-card>

        <div class="space-y-6">
            <x-card :title="__('Add a reason')">
                {{-- Each kind of reason asks only what matters for it: whose fault (points) for order reasons, break limit for break reasons. --}}
                <form method="POST" action="{{ route('settings.reasons.store') }}" class="space-y-2"
                    x-data="{ type: 'cancel', types: {{ \Illuminate\Support\Js::from(array_keys($typeLabels)) }} }"
                    @select-change="if (types.includes($event.detail)) type = $event.detail">
                    @csrf
                    <x-simple-select name="reason_type" :options="$typeLabels" value="cancel" full-width class="w-full" />
                    <input name="label_en" required maxlength="150" placeholder="{{ __('Reason text') }}" class="{{ $input }}">
                    <div x-show="type !== 'break'">
                        <p class="mb-1 text-xs text-gray-500">{{ __('Whose fault is it? (used for points: only that stage loses points)') }}</p>
                        <x-simple-select name="blame_stage" :options="$blame" value="none" full-width class="w-full" />
                    </div>
                    <div x-show="type === 'break'" x-cloak class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <label class="flex items-start gap-2 text-sm text-gray-700">
                            <input type="hidden" name="counts_as_break" value="0">
                            <input type="checkbox" name="counts_as_break" value="1" checked class="mt-0.5 rounded border-gray-300 text-primary">
                            <span>
                                <span class="font-medium">{{ __('Counts towards the daily break limit') }}</span>
                                <span class="mt-0.5 block text-xs text-gray-500">{{ __('Ticked: personal time (lunch, prayer, washroom). Unticked: work away from the desk (an errand the admin gave), so it is not counted as a break.') }}</span>
                            </span>
                        </label>
                    </div>
                    <x-button>{{ __('Add reason') }}</x-button>
                </form>
            </x-card>

            @foreach ($typeLabels as $type => $label)
                @continue(! isset($reasons[$type]))
                <x-card :title="$label">
                    @foreach ($reasons[$type] as $r)
                        <div @class(['flex items-center justify-between gap-2 py-1 text-sm', 'opacity-40' => ! $r->is_active])>
                            <span>{{ $r->label_en }} <span class="text-xs text-gray-400">· {{ $type === 'break' ? ($r->counts_as_break ? __('counts as break') : __('work, not counted')) : $blame[$r->blame_stage] }}</span></span>
                            <form method="POST" action="{{ route('settings.reasons.toggle', $r->id) }}">@csrf<button class="text-xs text-primary hover:underline">{{ $r->is_active ? __('Hide') : __('Show') }}</button></form>
                        </div>
                    @endforeach
                </x-card>
            @endforeach
        </div>
    </div>
</x-layouts.app>
