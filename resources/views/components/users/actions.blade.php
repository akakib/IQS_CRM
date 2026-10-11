@props(['user'])

{{-- Rendered twice per row (table + card), so the form id carries a random suffix. --}}
@php($formId = 'user-status-'.$user->id.'-'.\Illuminate\Support\Str::random(4))

<div class="inline-flex items-center gap-3">
    @can('staff.edit')
        <a href="{{ route('users.edit', $user) }}" class="text-sm font-medium text-primary hover:underline">{{ __('Edit') }}</a>
    @endcan
    @can('access.manage')
        <a href="{{ route('users.access', $user) }}" class="text-sm font-medium text-primary hover:underline">{{ __('Access') }}</a>
    @endcan
    @if (! $user->is(auth()->user()) && auth()->user()->can('staff.delete'))
        <form id="{{ $formId }}" method="POST" action="{{ route('users.status', $user) }}">
            @csrf
            @method('PATCH')
            @if ($user->is_active)
                <input type="hidden" name="give_to" value="">
                {{-- The popup first shows what they still hold and who takes it over. --}}
                <button type="button" class="text-sm font-medium text-red-600 hover:underline"
                    @click="$dispatch('deactivate-staff', { url: @js(route('users.work', $user)), form: @js($formId), name: @js($user->name) })">{{ __('Deactivate') }}</button>
            @else
                <button type="button" class="text-sm font-medium text-primary hover:underline"
                    @click="$dispatch('open-confirm', { id: 'user-status', form: @js($formId), label: @js($user->name), verb: @js(__('Activate')), message: @js(__('They will be able to log in again.')), danger: false })">{{ __('Activate') }}</button>
            @endif
        </form>
    @endif
</div>
