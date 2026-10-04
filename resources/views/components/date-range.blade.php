{{-- From/To dates with quick presets. Inside a filter bar it submits on change.
     <x-date-range from-name="from" to-name="to" :from="$list->filter('from')" :to="$list->filter('to')" /> --}}
@props(['fromName' => 'from', 'toName' => 'to', 'from' => null, 'to' => null])

@php
    $today = now();
    $presets = [
        __('Today') => [$today->toDateString(), $today->toDateString()],
        __('Yesterday') => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
        __('Last 7 days') => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
        __('This month') => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}
    x-data="{ from: @js($from), to: @js($to), changed() { this.$nextTick(() => this.$dispatch('select-change', 'dates')) } }">
    <x-dropdown align="left">
        <x-slot:trigger><span x-text="from || to ? (from ?? '…') + ' → ' + (to ?? '…') : @js(__('Any date'))"></span></x-slot:trigger>
        @foreach ($presets as $label => [$f, $t])
            <button type="button" @click="from = @js($f); to = @js($t); changed()">{{ $label }}</button>
        @endforeach
        <button type="button" @click="from = null; to = null; changed()">{{ __('Any date') }}</button>
    </x-dropdown>
    <input type="date" name="{{ $fromName }}" x-model="from" @change="changed()" aria-label="{{ __('From') }}"
        class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm focus:border-green-800 focus:outline-none">
    <input type="date" name="{{ $toName }}" x-model="to" @change="changed()" aria-label="{{ __('To') }}"
        class="rounded-lg border border-gray-300 px-2.5 py-2 text-sm focus:border-green-800 focus:outline-none">
</div>
