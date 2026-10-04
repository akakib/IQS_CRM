<div class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6">
    @csrf

    <div class="grid gap-x-4 md:grid-cols-2">
        <x-form.input name="name" :label="__('Name')" :value="$user->name" required maxlength="255" autofocus />
        <x-form.input name="email" type="email" :label="__('Email (login)')" :value="$user->email" required maxlength="255" />
        <x-form.input name="phone" :label="__('Phone')" :value="$user->phone" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX" />
        <x-form.input name="telegram_user_id" :label="__('Telegram user ID')" :value="$user->telegram_user_id" maxlength="50" />

        <div class="mb-4">
            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Employment type') }}</label>
            <x-simple-select name="employment_type" :options="['' => __('Not set')] + $employmentOptions" :value="old('employment_type', $user->employment_type?->value ?? '')" full-width class="w-full" />
            @error('employment_type')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-4">
            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Work location') }}</label>
            <x-simple-select name="work_location_id" :options="['' => __('Not set')] + $locationOptions" :value="(string) old('work_location_id', $user->work_location_id ?? '')" full-width class="w-full" />
            @error('work_location_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="mt-2 border-t border-gray-100 pt-4">
        <p class="mb-3 text-sm font-semibold text-gray-800">{{ $user->exists ? __('Reset password') : __('Password') }}</p>
        @if ($user->exists)
            <p class="mb-3 text-xs text-gray-500">{{ __('Leave empty to keep the current password. A new password logs them out everywhere.') }}</p>
        @endif
        <div class="grid gap-x-4 md:grid-cols-2">
            <x-form.input name="password" type="password" :label="__('New password')" autocomplete="new-password" :required="! $user->exists" />
            <x-form.input name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" :required="! $user->exists" />
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="rounded-lg bg-green-900 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">{{ __('Save') }}</button>
        <a href="{{ route('users.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Cancel') }}</a>
    </div>
</div>
