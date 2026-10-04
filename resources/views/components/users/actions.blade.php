@props(['user'])

{{-- Rendered twice per row (table + card), so the form id carries a random suffix. --}}
@php($formId = 'user-status-'.$user->id.'-'.\Illuminate\Support\Str::random(4))

<div class="inline-flex items-center gap-3">
    <a href="{{ route('users.edit', $user) }}" class="text-sm font-medium text-green-900 hover:underline">{{ __('Edit') }}</a>
    @unless ($user->is(auth()->user()))
        <form id="{{ $formId }}" method="POST" action="{{ route('users.status', $user) }}">
            @csrf
            @method('PATCH')
            @if ($user->is_active)
                <button type="button" class="text-sm font-medium text-red-600 hover:underline"
                    @click="$dispatch('open-confirm', { id: 'user-status', form: @js($formId), label: @js($user->name), verb: @js(__('Deactivate')), message: @js(__('They will be logged out and cannot log in until activated again.')), danger: true })">{{ __('Deactivate') }}</button>
            @else
                <button type="button" class="text-sm font-medium text-green-900 hover:underline"
                    @click="$dispatch('open-confirm', { id: 'user-status', form: @js($formId), label: @js($user->name), verb: @js(__('Activate')), message: @js(__('They will be able to log in again.')), danger: false })">{{ __('Activate') }}</button>
            @endif
        </form>
    @endunless
</div>
