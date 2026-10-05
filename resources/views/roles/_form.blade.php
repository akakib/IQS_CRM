@csrf

<div class="space-y-6">
    <div class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6">
        <x-form.input name="name" :label="__('Role name')" :value="$role->name" required maxlength="100" autofocus />
        <x-form.input name="description" :label="__('Description')" :value="$role->description" maxlength="255" />
    </div>

    @if ($role->isOwner())
        <div class="max-w-2xl rounded-xl border border-green-200 bg-primary-soft p-4 text-sm text-primary">
            {{ __('Owner always has every permission, including modules added later, and sees every field.') }}
        </div>
    @else
        {{-- Permission matrix: one row per module, ticked = allowed. --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3">
                <p class="text-sm font-semibold text-gray-800">{{ __('Permissions') }}</p>
                <p class="text-xs text-gray-500">{{ __('Unticked means not allowed. New modules appear here unticked.') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('Module') }}</th>
                            @foreach ($actions as $action)
                                <th class="px-2 py-3 text-center">{{ __(\Illuminate\Support\Str::headline($action)) }}</th>
                            @endforeach
                            <th class="px-4 py-3">{{ __('Data') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($modules as $module => $m)
                            <tr x-data>
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $m['label'] }}</td>
                                @foreach ($actions as $action)
                                    <td class="px-2 py-3 text-center">
                                        @isset($m['actions'][$action])
                                            @php($key = $module.'.'.$action)
                                            <label class="inline-flex flex-col items-center gap-0.5" title="{{ $m['label'] }}: {{ $m['actions'][$action] }}">
                                                <input type="checkbox" name="grants[]" value="{{ $key }}" @checked(in_array($key, old('grants', array_keys($grants)), true))
                                                    class="h-4 w-4 rounded border-gray-300 text-primary">
                                                @if ($m['actions'][$action] !== __(\Illuminate\Support\Str::headline($action)))
                                                    <span class="text-[10px] text-gray-400">{{ $m['actions'][$action] }}</span>
                                                @endif
                                            </label>
                                        @else
                                            <span class="text-gray-300">-</span>
                                        @endisset
                                    </td>
                                @endforeach
                                <td class="px-4 py-3">
                                    <x-simple-select name="scopes[{{ $module }}]" size="sm"
                                        :options="['all' => __('All records'), 'own' => __('Own only')]"
                                        :value="old('scopes.'.$module, $scopes[$module] ?? 'all')" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6">
            <p class="text-sm font-semibold text-gray-800">{{ __('Hidden fields') }}</p>
            <p class="mb-3 text-xs text-gray-500">{{ __('Ticked fields are masked for this role, e.g. 017******78. If a person has another role that shows the field, they see it.') }}</p>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($maskFields as $field => $label)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="masks[]" value="{{ $field }}" @checked(in_array($field, old('masks', array_keys($masks)), true))
                            class="h-4 w-4 rounded border-gray-300 text-primary">
                        {{ __('Hide :field', ['field' => mb_strtolower($label)]) }}
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex items-center gap-2">
        <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark">{{ __('Save') }}</button>
        <a href="{{ route('roles.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Cancel') }}</a>
    </div>
</div>
