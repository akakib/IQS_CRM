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
    x-data="{ from: @js($from), to: @js($to), changed() { this.$nextTick(() => this.$dispatch('select-change', 'dates')) } }"
    @date-change="changed()">
    <x-dropdown align="left">
        <x-slot:trigger><span x-text="from || to ? (from ?? '…') + ' → ' + (to ?? '…') : @js(__('Any date'))"></span></x-slot:trigger>
        @foreach ($presets as $label => [$f, $t])
            <button type="button" @click="from = @js($f); to = @js($t); changed()">{{ $label }}</button>
        @endforeach
        <button type="button" @click="from = null; to = null; changed()">{{ __('Any date') }}</button>
    </x-dropdown>
    <x-date-input :name="$fromName" :value="$from" x-model="from" :placeholder="__('From')" />
    <x-date-input :name="$toName" :value="$to" x-model="to" :placeholder="__('To')" />
</div>
