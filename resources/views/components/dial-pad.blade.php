{{-- A big on-screen number pad under a number box, so a number goes in with the thumb (phone) or the mouse (PC);
     the keyboard still works. The last key opens what was typed.
     <x-dial-pad model="cq" submit="cfind()" :label="__('Open')" busy="cfinding" />
     model: the Alpine field the digits go into. submit: what the green key runs. busy: an Alpine expression. --}}
@props(['model', 'submit', 'label', 'busy' => 'false'])

<div class="grid grid-cols-3 gap-2 md:mx-auto md:max-w-xs" {{ $attributes }}>
    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $d)
        <button type="button" @click="{{ $model }} = String({{ $model }} || '') + '{{ $d }}'; navigator.vibrate?.(8)"
            class="h-12 rounded-xl border border-gray-300 bg-white text-xl font-semibold tabular-nums text-gray-900 hover:bg-gray-50 active:bg-gray-100">{{ $d }}</button>
    @endforeach
    <button type="button" @click="{{ $model }} = String({{ $model }} || '').slice(0, -1); navigator.vibrate?.(8)"
        class="h-12 rounded-xl border border-gray-300 bg-white text-lg text-gray-600 hover:bg-gray-50 active:bg-gray-100" aria-label="{{ __('Delete') }}">⌫</button>
    <button type="button" @click="{{ $model }} = String({{ $model }} || '') + '0'; navigator.vibrate?.(8)"
        class="h-12 rounded-xl border border-gray-300 bg-white text-xl font-semibold text-gray-900 hover:bg-gray-50 active:bg-gray-100">0</button>
    <button type="button" @click="{{ $submit }}" :disabled="{{ $busy }}" class="h-12 rounded-xl bg-primary text-base font-semibold text-white hover:bg-primary-dark disabled:opacity-60">{{ $label }}</button>
</div>
