@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    $typeLabels = ['cancel' => __('Cancel reasons'), 'hold' => __('Hold reasons'), 'amendment' => __('Order change reasons'), 'return' => __('Return reasons'), 'reassign' => __('Owner change reasons'), 'status' => __('Other')];
    $blame = ['none' => __('Nobody'), 'sales' => __('Sales / agent'), 'verification' => __('Verification'), 'packing' => __('Packing'), 'dispatch' => __('Dispatch'), 'courier' => __('Courier'), 'customer' => __('Customer')];
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
                <form method="POST" action="{{ route('settings.reasons.store') }}" class="space-y-2">
                    @csrf
                    <x-simple-select name="reason_type" :options="$typeLabels" value="cancel" full-width class="w-full" />
                    <input name="label_en" required maxlength="150" placeholder="{{ __('Reason text') }}" class="{{ $input }}">
                    <p class="text-xs text-gray-500">{{ __('Whose fault is it? (used for points: only that stage loses points)') }}</p>
                    <x-simple-select name="blame_stage" :options="$blame" value="none" full-width class="w-full" />
                    <x-button>{{ __('Add reason') }}</x-button>
                </form>
            </x-card>

            @foreach ($typeLabels as $type => $label)
                @continue(! isset($reasons[$type]))
                <x-card :title="$label">
                    @foreach ($reasons[$type] as $r)
                        <div @class(['flex items-center justify-between gap-2 py-1 text-sm', 'opacity-40' => ! $r->is_active])>
                            <span>{{ $r->label_en }} <span class="text-xs text-gray-400">· {{ $blame[$r->blame_stage] }}</span></span>
                            <form method="POST" action="{{ route('settings.reasons.toggle', $r->id) }}">@csrf<button class="text-xs text-green-900 hover:underline">{{ $r->is_active ? __('Hide') : __('Show') }}</button></form>
                        </div>
                    @endforeach
                </x-card>
            @endforeach
        </div>
    </div>
</x-layouts.app>
