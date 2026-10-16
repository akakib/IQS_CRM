{{-- The download buttons of a list, the same everywhere: "Print / PDF" (the clean print page; the browser's window prints it
     or saves it as a PDF) and Excel. print = the print page's URL.
     <x-export-buttons :print="route('calls.lists.print', $q)" :excel="route('calls.lists.excel', $q)" />
     Alpine-built URLs: <x-export-buttons print-js="url + '?' + query()" excel-js="..." disabled-js="! total" /> --}}
@props(['print' => null, 'excel' => null, 'printJs' => null, 'excelJs' => null, 'disabledJs' => null, 'size' => 'sm'])

@php
    $btn = 'inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white font-medium text-gray-700 hover:bg-gray-50 '.($size === 'sm' ? 'min-h-8 px-2.5 py-1.5 text-xs' : 'min-h-10 px-4 py-2 text-sm');
    $icon = $size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1.5']) }}>
    @if ($printJs)
        <a :href="{{ $printJs }}" target="_blank" rel="noopener" @if ($disabledJs) :class="({{ $disabledJs }}) && 'pointer-events-none opacity-50'" @endif class="{{ $btn }}"><x-icon name="printer" :class="$icon" /> {{ __('Print / PDF') }}</a>
        <a :href="{{ $excelJs }}" @if ($disabledJs) :class="({{ $disabledJs }}) && 'pointer-events-none opacity-50'" @endif class="{{ $btn }}"><x-icon name="download" :class="$icon" /> {{ __('Excel') }}</a>
    @else
        <a href="{{ $print }}" target="_blank" rel="noopener" class="{{ $btn }}" title="{{ __('Print, or save as a PDF file') }}"><x-icon name="printer" :class="$icon" /> {{ __('Print / PDF') }}</a>
        <a href="{{ $excel }}" class="{{ $btn }}" title="{{ __('Excel') }}"><x-icon name="download" :class="$icon" /> {{ __('Excel') }}</a>
    @endif
</div>
