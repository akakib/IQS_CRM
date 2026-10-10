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
            @php $formId = 'delete-role-'.$role->id; @endphp
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
                        @php $names = ($holders[$role->id] ?? collect())->pluck('name')->join(', '); @endphp
                        @if (auth()->user()->can('access.manage') && ! $role->isOwner())
                            <button type="button" @click="$dispatch('open-modal', 'people-{{ $role->id }}')" class="font-medium text-primary hover:underline" title="{{ $names }}">{{ trans_choice(':count staff|:count staff', $role->users_count, ['count' => $role->users_count]) }}</button>
                        @else
                            <span title="{{ $names }}">{{ trans_choice(':count staff|:count staff', $role->users_count, ['count' => $role->users_count]) }}</span>
                        @endif
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
            @if (auth()->user()->can('access.manage') && ! $role->isOwner())
                <x-modal :id="'people-'.$role->id" :title="__('People with :r', ['r' => $role->name])" persistent>
                    <form method="POST" action="{{ route('roles.people', $role) }}" id="people-form-{{ $role->id }}"
                        x-data="{ picked: @js(($holders[$role->id] ?? collect())->pluck('id')->map(fn ($i) => (int) $i)->values()), staff: @js($staff->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()), q: '',
                            name(id) { return (this.staff.find(u => u.id === id) || {}).name },
                            toggle(id) { this.picked.includes(id) ? this.picked = this.picked.filter(x => x !== id) : this.picked.push(id) } }">
                        @csrf
                        <p class="mb-3 text-sm text-gray-500">{{ __('They get this role for good. Temporary access is given on the Access page of each person.') }}</p>
                        <template x-for="id in picked" :key="id"><input type="hidden" name="users[]" :value="id"></template>
                        <div class="mb-2 flex min-h-[2.25rem] flex-wrap gap-1.5">
                            <template x-for="id in picked" :key="id">
                                <span class="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-medium text-primary"><span x-text="name(id)"></span>
                                    <button type="button" @click="toggle(id)" class="text-primary/70 hover:text-primary" aria-label="{{ __('Remove') }}">&times;</button></span>
                            </template>
                            <span x-show="! picked.length" class="py-1 text-sm text-gray-400">{{ __('Nobody yet') }}</span>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white">
                            <div class="border-b border-gray-100 p-2"><input type="text" x-model="q" placeholder="{{ __('Search staff') }}" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary focus:outline-none"></div>
                            <div class="max-h-60 overflow-y-auto py-1">
                                <template x-for="u in staff.filter(u => u.name.toLowerCase().includes(q.toLowerCase()))" :key="u.id">
                                    <label class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50">
                                        <input type="checkbox" :checked="picked.includes(u.id)" @change="toggle(u.id)" class="rounded border-gray-300 text-primary focus:ring-primary"><span x-text="u.name"></span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </form>
                    <x-slot:footer>
                        <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'people-{{ $role->id }}')">{{ __('Cancel') }}</x-button>
                        <x-button type="submit" form="people-form-{{ $role->id }}">{{ __('Save') }}</x-button>
                    </x-slot:footer>
                </x-modal>
            @endif
        @endforeach
    </div>

    <x-confirm-modal id="delete-role" :message="__('Only possible when no staff has this role.')" />
</x-layouts.app>
