<div class="max-w-xl rounded-xl border border-gray-200 bg-white p-6">
    @csrf

    <div class="mb-4">
        <label for="name" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Name') }}</label>
        <input id="name" type="text" name="name" value="{{ old('name', $location->name) }}" required maxlength="100" autofocus
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none">
        @error('name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="mb-4">
        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Type') }}</label>
        <x-simple-select name="type" :options="$typeOptions" :value="old('type', $location->type?->value ?? array_key_first($typeOptions))" full-width class="w-full" />
        @error('type')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <label class="mb-6 flex items-center gap-2 text-sm text-gray-700">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $location->is_active)) class="rounded border-gray-300 text-green-900">
        {{ __('Active') }}
    </label>

    <div class="flex items-center gap-2">
        <button type="submit" class="rounded-lg bg-green-900 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">{{ __('Save') }}</button>
        <a href="{{ route('locations.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Cancel') }}</a>
    </div>
</div>
