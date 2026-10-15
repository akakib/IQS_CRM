{{-- The one box for a call's recording or screenshot (Call lists, order calls, customer calls in, rider calls).
     <x-call-proof id="proof-in" />                         recording or screenshot
     <x-call-proof id="proof-list" kind="spoke() ? 'audio' : 'image'" title="..." />   kind and title are Alpine expressions of the page
     Saved by a normal form: the inputs are named proof, proof_modified, proof_old_ok.
     Saved by fetch: window.iqsAddProof(formData, id). Picked or cleared: a "proof-changed" event { id, name }. --}}
@props(['id', 'kind' => "'any'", 'title' => null, 'size' => 'md'])

@php
    $audio = 'audio/*,.m4a,.amr,.mp3,.aac,.ogg,.opus,.wav,.3gp';
    $small = $size === 'sm';
@endphp

<div id="{{ $id }}" x-data="callProof(@js($id))" {{ $attributes }}>
    @if ($title)<p class="mb-2 text-sm font-semibold text-gray-900" x-text="{{ $title }}"></p>@endif
    <label class="flex cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed {{ $small ? 'px-2.5 py-2' : 'px-3 py-3' }}" :class="ready() ? 'border-green-500 bg-green-50' : (old ? 'border-amber-400 bg-amber-50' : 'border-gray-300 bg-white')">
        <span class="flex {{ $small ? 'h-8 w-8' : 'h-10 w-10' }} shrink-0 items-center justify-center rounded-full text-lg" :class="ready() ? 'bg-green-600 text-white' : 'bg-gray-100'" x-text="ready() ? '✓' : '+'"></span>
        <span class="min-w-0">
            <span class="block break-all text-sm font-medium text-gray-900"
                x-text="file || ({{ $kind }} === 'audio' ? @js(__('Add the recording')) : ({{ $kind }} === 'image' ? @js(__('Add the screenshot')) : @js(__('Add the recording or a screenshot'))))"></span>
            <span class="block text-xs text-gray-500">{{ __('From your phone: the newest one is on top.') }}</span>
        </span>
        <input type="file" name="proof" class="sr-only" @change="pick($event)"
            :accept="{{ $kind }} === 'audio' ? @js($audio) : ({{ $kind }} === 'image' ? 'image/*' : @js($audio.',image/*'))">
    </label>
    <input type="hidden" name="proof_modified" :value="modified">
    <label x-show="old" x-cloak class="mt-2 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
        <input type="checkbox" name="proof_old_ok" value="1" x-model="ok" @change="emit()" class="mt-0.5 rounded border-amber-400 text-primary focus:ring-primary">
        <span><span x-text="@js(__('This file is from')) + ' ' + old + '.'"></span> {{ __('It is the right file') }}</span>
    </label>
</div>
