<x-layouts.app :heading="__('Roles')">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ __('A role is a set of rules shared by everyone who has it.') }}</p>
        @can('access.manage')
            <a href="{{ route('roles.create') }}"
                class="shrink-0 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark">+ {{ __('New role') }}</a>
        @endcan
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($roles as $role)
            @php($formId = 'delete-role-'.$role->id)
            <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex items-start justify-between gap-2">
                    <p class="font-medium text-gray-800">{{ $role->name }}</p>
                    @if ($role->isOwner())
                        <span class="rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-800">{{ __('Full access') }}</span>
                    @endif
                </div>
                <p class="mt-1 flex-1 text-sm text-gray-500">{{ $role->description ?: '-' }}</p>
                <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3 text-xs text-gray-500">
                    <span>
                        {{ trans_choice(':count staff|:count staff', $role->users_count, ['count' => $role->users_count]) }}
                        @unless ($role->isOwner())
                            · {{ trans_choice(':count permission|:count permissions', $role->permissions_count, ['count' => $role->permissions_count]) }}
                        @endunless
                    </span>
                    @can('access.manage')
                        <span class="inline-flex items-center gap-3">
                            <a href="{{ route('roles.edit', $role) }}" class="text-sm font-medium text-primary hover:underline">{{ __('Edit') }}</a>
                            @unless ($role->isOwner())
                                <form id="{{ $formId }}" method="POST" action="{{ route('roles.destroy', $role) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" class="text-sm font-medium text-red-600 hover:underline"
                                        @click="$dispatch('open-confirm', { id: 'delete-role', form: @js($formId), label: @js($role->name) })">{{ __('Delete') }}</button>
                                </form>
                            @endunless
                        </span>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>

    <x-confirm-modal id="delete-role" :message="__('Only possible when no staff has this role.')" />
</x-layouts.app>
