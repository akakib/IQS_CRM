@php
    $when = function ($row) {
        $parts = [];
        if ($row->starts_at && \Illuminate\Support\Carbon::parse($row->starts_at)->isFuture()) {
            $parts[] = __('from :t', ['t' => \Illuminate\Support\Carbon::parse($row->starts_at)->format('d M Y, g:i A')]);
        }
        $parts[] = $row->expires_at
            ? __('until :t', ['t' => \Illuminate\Support\Carbon::parse($row->expires_at)->format('d M Y, g:i A')])
            : __('permanent');

        return implode(' ', $parts);
    };
    $expired = fn ($row) => $row->expires_at && \Illuminate\Support\Carbon::parse($row->expires_at)->isPast();
    $inputClass = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
@endphp

<x-layouts.app :heading="__('Access: :name', ['name' => $user->name])">
    <div class="mb-4">
        <a href="{{ route('users.index') }}" class="text-sm text-primary hover:underline">{{ __('Back to staff') }}</a>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        {{-- Roles --}}
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-gray-800">{{ __('Roles') }}</h2>
            <p class="mb-4 text-xs text-gray-500">{{ __('Rules shared with everyone who has the role. A temporary role ends by itself.') }}</p>

            <div class="mb-4 space-y-2">
                @forelse ($assignments as $a)
                    @php($formId = 'remove-role-'.$a->id)
                    <div @class(['flex items-start justify-between gap-3 rounded-lg border px-3 py-2', 'border-gray-200' => ! $expired($a), 'border-dashed border-gray-200 opacity-60' => $expired($a)])>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800">{{ $a->name }}
                                @if ($a->expires_at)
                                    <span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ $expired($a) ? __('Expired') : __('Temporary') }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $when($a) }}@if ($a->reason) · {{ $a->reason }}@endif @if ($a->by_name) · {{ __('by :name', ['name' => $a->by_name]) }}@endif</p>
                        </div>
                        <form id="{{ $formId }}" method="POST" action="{{ route('users.access.roles.destroy', [$user, $a->id]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="button" class="text-sm font-medium text-red-600 hover:underline"
                                @click="$dispatch('open-confirm', { id: 'access-remove', form: @js($formId), label: @js($a->name), verb: @js(__('Remove')), message: @js(__('Access from this role stops right away.')) })">{{ __('Remove') }}</button>
                        </form>
                    </div>
                @empty
                    <p class="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500">{{ __('No role yet. Without a role they only see the dashboard.') }}</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('users.access.roles.store', $user) }}" class="space-y-3 border-t border-gray-100 pt-4">
                @csrf
                <p class="text-sm font-medium text-gray-700">{{ __('Give a role') }}</p>
                <x-simple-select name="role_id" :options="['' => __('Choose a role')] + $roleOptions" :value="(string) old('role_id', '')" full-width class="w-full" />
                @error('role_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs text-gray-500">{{ __('Starts (optional)') }}
                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" class="{{ $inputClass }} mt-1"></label>
                    <label class="text-xs text-gray-500">{{ __('Ends (empty = permanent)') }}
                        <input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}" class="{{ $inputClass }} mt-1"></label>
                </div>
                @error('expires_at') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <input type="text" name="reason" value="{{ old('reason') }}" maxlength="255" placeholder="{{ __('Reason (required for temporary)') }}" class="{{ $inputClass }}">
                @error('reason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark">{{ __('Give role') }}</button>
            </form>
        </section>

        {{-- Personal allow / deny --}}
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-gray-800">{{ __('Custom access for :name', ['name' => $user->name]) }}</h2>
            <p class="mb-4 text-xs text-gray-500">{{ __('Allow adds one permission, Deny takes one away. Deny always wins over roles.') }}</p>

            <div class="mb-4 space-y-2">
                @forelse ($overrides as $o)
                    @php($formId = 'remove-override-'.$o->id)
                    <div @class(['flex items-start justify-between gap-3 rounded-lg border px-3 py-2', 'border-gray-200' => ! $expired($o), 'border-dashed border-gray-200 opacity-60' => $expired($o)])>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800">
                                <span @class(['mr-1 rounded-full px-2 py-0.5 text-xs font-medium', 'bg-green-50 text-green-800' => $o->effect === 'allow', 'bg-red-50 text-red-700' => $o->effect === 'deny'])>{{ $o->effect === 'allow' ? __('Allow') : __('Deny') }}</span>
                                {{ $permissionOptions[$o->key] ?? $o->key }}
                                @if ($o->effect === 'allow' && $o->data_scope === 'own') <span class="text-xs text-gray-500">({{ __('own only') }})</span> @endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $when($o) }} · {{ $o->reason }}@if ($o->by_name) · {{ __('by :name', ['name' => $o->by_name]) }}@endif</p>
                        </div>
                        <form id="{{ $formId }}" method="POST" action="{{ route('users.access.overrides.destroy', [$user, $o->id]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="button" class="text-sm font-medium text-red-600 hover:underline"
                                @click="$dispatch('open-confirm', { id: 'access-remove', form: @js($formId), label: @js($permissionOptions[$o->key] ?? $o->key), verb: @js(__('Remove')), message: @js(__('They go back to what their roles allow.')) })">{{ __('Remove') }}</button>
                        </form>
                    </div>
                @empty
                    <p class="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500">{{ __('Nothing custom. They get exactly what their roles allow.') }}</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('users.access.overrides.store', $user) }}" class="space-y-3 border-t border-gray-100 pt-4" x-data="{ effect: @js(old('effect', 'allow')) }" @select-change="if (['allow','deny'].includes($event.detail)) effect = $event.detail">
                @csrf
                <p class="text-sm font-medium text-gray-700">{{ __('Add custom access') }}</p>
                <x-simple-select name="permission" :options="['' => __('Choose a permission')] + $permissionOptions" :value="old('permission', '')" full-width class="w-full" />
                @error('permission') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="grid grid-cols-2 gap-3">
                    <x-simple-select name="effect" :options="['allow' => __('Allow'), 'deny' => __('Deny')]" :value="old('effect', 'allow')" full-width class="w-full" />
                    <div x-show="effect === 'allow'">
                        <x-simple-select name="data_scope" :options="['all' => __('All records'), 'own' => __('Own only')]" :value="old('data_scope', 'all')" full-width class="w-full" />
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs text-gray-500">{{ __('Starts (optional)') }}
                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" class="{{ $inputClass }} mt-1"></label>
                    <label class="text-xs text-gray-500">{{ __('Ends (empty = permanent)') }}
                        <input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}" class="{{ $inputClass }} mt-1"></label>
                </div>
                @error('expires_at') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <input type="text" name="reason" value="{{ old('reason') }}" maxlength="255" required placeholder="{{ __('Reason') }}" class="{{ $inputClass }}">
                @error('reason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark">{{ __('Save custom access') }}</button>
            </form>
        </section>
    </div>

    {{-- What they can do right now, after roles + custom access --}}
    <section class="mt-6 rounded-xl border border-gray-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-gray-800">{{ __('What :name can do right now', ['name' => $user->name]) }}</h2>
        @if ($map->isOwner)
            <p class="mt-2 text-sm text-primary">{{ __('Owner: everything, every field.') }}</p>
        @else
            <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($modules as $module => $m)
                    <div class="rounded-lg border border-gray-100 p-3">
                        <p class="mb-1 text-sm font-medium text-gray-800">{{ $m['label'] }}</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($m['actions'] as $action => $label)
                                @php($scope = $map->scope($module.'.'.$action))
                                <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-green-50 text-green-800' => $scope, 'bg-gray-100 text-gray-400 line-through' => ! $scope])>{{ $label }}@if ($scope === 'own') ({{ __('own') }})@endif</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('Hidden fields') }}:
                {{ collect($maskFields)->filter(fn ($l, $f) => $map->hides($f))->values()->join(', ') ?: __('none') }}</p>
        @endif
    </section>

    <x-confirm-modal id="access-remove" />
</x-layouts.app>
