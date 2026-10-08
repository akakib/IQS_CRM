@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none')

<x-layouts.app :heading="__('Communication channels')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Every place customers write to, one row each: each WhatsApp number, each page (Messenger and comments as two channels), Instagram and so on. Add the number delivery men call as a Rider line. Give each channel to people with Access (or on the Staff page); they see only theirs in Communication. Rider line access gives the Rider calls tab. Orders made from a chat remember the channel, so you can see which number or page brings orders.') }}</p>

    @can('settings.edit')
        <x-card :title="__('Add a channel')" class="mb-6">
            <form method="POST" action="{{ route('settings.chat-channels.save') }}" class="grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-end">
                @csrf
                <label class="text-sm font-medium text-gray-700">{{ __('Name') }}
                    <input name="name" required maxlength="80" value="{{ old('name') }}" placeholder="{{ __('e.g. WhatsApp 2 (01711...)') }}" class="{{ $input }} mt-1">
                </label>
                <div>
                    <p class="mb-1 text-sm font-medium text-gray-700">{{ __('Type') }}</p>
                    <x-simple-select name="type" :options="$types" :value="old('type', 'whatsapp')" full-width class="w-full" />
                </div>
                <x-button>{{ __('Add') }}</x-button>
            </form>
            @error('name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        </x-card>
    @endcan

    @if ($channels->isEmpty())
        <x-empty-state :message="__('No channels yet. Add each WhatsApp number and page above.')" />
    @else
        <div class="space-y-2">
            @foreach ($channels as $c)
                <form method="POST" action="{{ route('settings.chat-channels.save', $c) }}" class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-2 sm:items-center lg:grid-cols-[1fr_13rem_5rem_auto_14rem_auto]">
                    @csrf
                    <div class="flex items-center gap-2">
                        <x-channel-icon :type="$c->type" />
                        <input name="name" required maxlength="80" value="{{ $c->name }}" class="{{ $input }}" aria-label="{{ __('Name') }}" @cannot('settings.edit') disabled @endcannot>
                    </div>
                    <x-simple-select name="type" :options="$types" :value="$c->type" full-width class="w-full" />
                    <input name="sort_order" type="number" min="0" max="999" value="{{ $c->sort_order }}" class="{{ $input }} tabular-nums" aria-label="{{ __('Order in the list') }}" title="{{ __('Order in the list') }}">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($c->is_active) class="rounded border-gray-300 text-primary focus:ring-primary">
                        {{ __('In use') }}
                    </label>
                    {{-- Who has it. Click to change (staff.edit). --}}
                    @php($names = $c->users->pluck('name')->join(', '))
                    @can('staff.edit')
                        <button type="button" @click="$dispatch('open-modal', 'access-{{ $c->id }}')" title="{{ $names }}"
                            class="flex min-w-0 items-center justify-between gap-2 rounded-full border border-gray-300 bg-white px-3 py-2 text-left text-sm hover:border-primary">
                            <span class="min-w-0 truncate"><span class="text-xs text-gray-500">{{ __('Access') }}:</span> <span @class(['text-gray-800' => $names, 'text-gray-400' => ! $names])>{{ $names ?: __('Nobody') }}</span></span>
                            <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                        </button>
                    @else
                        <p class="min-w-0 truncate text-sm" title="{{ $names }}"><span class="text-xs text-gray-500">{{ __('Access') }}:</span> {{ $names ?: __('Nobody') }}</p>
                    @endcan
                    @can('settings.edit')<x-button size="sm" variant="secondary">{{ __('Save') }}</x-button>@endcan
                </form>
                @can('staff.edit')
                    <x-modal :id="'access-'.$c->id" :title="__('Access: :c', ['c' => $c->name])" persistent>
                        <form method="POST" action="{{ route('settings.chat-channels.people', $c) }}"
                            x-data="{ picked: @js($c->users->pluck('id')->all()), staff: @js($staff->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()), q: '', panel: true,
                                name(id) { return (this.staff.find(u => u.id === id) || {}).name },
                                toggle(id) { this.picked.includes(id) ? this.picked = this.picked.filter(x => x !== id) : this.picked.push(id) } }">
                            @csrf
                            <p class="mb-3 text-sm text-gray-500">{{ __('They see this channel in Communication (a Rider line gives Rider calls). Same as the ticks on the Staff page.') }}</p>
                            <template x-for="id in picked" :key="id"><input type="hidden" name="users[]" :value="id"></template>
                            {{-- Chosen people as chips. --}}
                            <div class="mb-2 flex min-h-[2.25rem] flex-wrap gap-1.5">
                                <template x-for="id in picked" :key="id">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-medium text-primary">
                                        <span x-text="name(id)"></span>
                                        <button type="button" @click="toggle(id)" class="text-primary/70 hover:text-primary" aria-label="{{ __('Remove') }}">&times;</button>
                                    </span>
                                </template>
                                <span x-show="! picked.length" class="py-1 text-sm text-gray-400">{{ __('Nobody yet') }}</span>
                            </div>
                            {{-- Pick many: pill with chevron, panel with search and ticks. --}}
                            <div class="relative">
                                <button type="button" @click="panel = ! panel" class="flex w-full items-center justify-between rounded-full border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:border-primary">
                                    <span x-text="picked.length ? picked.length + ' {{ __('selected') }}' : '{{ __('Choose people') }}'"></span>
                                    <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform duration-150" :class="panel && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                                </button>
                                <div x-show="panel" class="mt-2 w-full rounded-xl border border-gray-200 bg-white shadow-sm">
                                    <div class="border-b border-gray-100 p-2">
                                        <input type="text" x-model="q" placeholder="{{ __('Search staff') }}" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary focus:outline-none">
                                    </div>
                                    <div class="max-h-60 overflow-y-auto py-1">
                                        <template x-for="u in staff.filter(u => u.name.toLowerCase().includes(q.toLowerCase()))" :key="u.id">
                                            <label class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50">
                                                <input type="checkbox" :checked="picked.includes(u.id)" @change="toggle(u.id)" class="rounded border-gray-300 text-primary focus:ring-primary">
                                                <span x-text="u.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 flex justify-end gap-2">
                                <x-button type="button" variant="secondary" size="sm" @click="close()">{{ __('Cancel') }}</x-button>
                                <x-button size="sm">{{ __('Save') }}</x-button>
                            </div>
                        </form>
                    </x-modal>
                @endcan
            @endforeach
        </div>
    @endif
</x-layouts.app>
