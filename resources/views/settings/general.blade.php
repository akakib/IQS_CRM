@php($canEdit = auth()->user()->can('settings.edit'))

<x-layouts.app :heading="__('Settings')">
    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="max-w-3xl space-y-6">
        @csrf
        <input type="hidden" name="settings_page" value="1">
        @method('PUT')

        @foreach ($groups as $group => $fields)
            <section class="rounded-xl border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-semibold text-gray-800">{{ __($group) }}</h2>
                <fieldset @disabled(! $canEdit) class="grid gap-x-4 md:grid-cols-2">
                    @foreach ($fields as $key => $f)
                        @php($name = str_replace('.', '_', $key))
                        @if ($key === 'store.logo')
                            <div class="mb-4 md:col-span-2" x-data="{ remove: false }">
                                <p class="mb-2 text-sm font-medium text-gray-700">{{ $f['label'] }}</p>
                                <div class="flex items-center gap-4">
                                    @if ($f['value'])
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($f['value']) }}" alt="" class="h-14 w-14 rounded-lg border border-gray-200 object-contain" :class="remove && 'opacity-30'">
                                    @endif
                                    <input type="file" name="store_logo" accept="image/*" class="text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm">
                                    @if ($f['value'])
                                        <label class="flex items-center gap-1.5 text-xs text-gray-600"><input type="checkbox" name="remove_logo" value="1" x-model="remove" class="rounded border-gray-300 text-primary"> {{ __('Remove') }}</label>
                                    @endif
                                </div>
                                @error('store_logo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @elseif ($key === 'appearance.primary_color')
                            <div class="mb-4" x-data="{ color: @js(old($name, $f['value'])) }">
                                <p class="mb-1.5 text-sm font-medium text-gray-700">{{ $f['label'] }}</p>
                                <div class="flex items-center gap-2">
                                    <input type="color" x-model="color" class="h-10 w-12 cursor-pointer rounded-lg border border-gray-300 p-1" aria-label="{{ __('Pick a colour') }}">
                                    <input type="text" name="{{ $name }}" x-model="color" maxlength="7" class="w-28 rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm focus:border-primary focus:outline-none">
                                    <span class="rounded-lg px-3 py-2 text-sm font-medium text-white" :style="'background:' + color">{{ __('Button') }}</span>
                                    <button type="button" @click="color = '#0d542b'" class="text-xs text-gray-500 hover:underline">{{ __('Reset') }}</button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ __('Choose a dark enough colour: button text is white.') }}</p>
                                @error($name)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @elseif ($key === 'appearance.font')
                            <div class="mb-4">
                                <p class="mb-1.5 text-sm font-medium text-gray-700">{{ $f['label'] }}</p>
                                <x-simple-select name="{{ $name }}" :options="array_combine(array_keys(config('appearance.fonts')), array_keys(config('appearance.fonts')))" :value="old($name, $f['value'])" full-width class="w-full" />
                                @error($name)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @elseif ($f['type'] === 'bool')
                            <label class="mb-4 flex items-center gap-2 text-sm text-gray-700 md:col-span-2">
                                <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $f['value'])) class="rounded border-gray-300 text-primary">
                                {{ $f['label'] }}
                            </label>
                        @elseif ($f['type'] === 'json')
                            <div class="mb-4 md:col-span-2" x-data="{ times: @js(old($name, $f['value'])) }">
                                <p class="mb-2 text-sm font-medium text-gray-700">{{ $f['label'] }}</p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <template x-for="(t, i) in times" :key="i">
                                        <div class="flex items-center gap-1">
                                            <input type="time" :name="'{{ $name }}[' + i + ']'" x-model="times[i]" required
                                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
                                            <button type="button" x-show="times.length > 1" @click="times.splice(i, 1)" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">&times;</button>
                                        </div>
                                    </template>
                                    <button type="button" x-show="times.length < 6" @click="times.push('18:00')" class="text-sm font-medium text-primary hover:underline">+ {{ __('Add time') }}</button>
                                </div>
                                @error($name)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                @error($name.'.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @else
                            <x-form.input :name="$name" :label="$f['label']" :value="$f['value']"
                                :type="in_array($f['type'], ['int', 'decimal']) ? 'number' : 'text'"
                                :step="$f['type'] === 'decimal' ? '0.01' : null" />
                        @endif
                    @endforeach
                </fieldset>
            </section>
        @endforeach

        @if ($canEdit)
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark">{{ __('Save settings') }}</button>
        @endif
    </form>
</x-layouts.app>
