{{-- Labeled text input with its validation error. Extra attributes go on the input. --}}
@props(['name', 'label', 'type' => 'text', 'value' => null])

<div class="mb-4">
    <label for="{{ $name }}" class="mb-2 block text-sm font-medium text-gray-700">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        {{ $attributes->merge(['class' => 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-green-800 focus:outline-none']) }}>
    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
