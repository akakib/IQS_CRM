<x-layouts.app :heading="__('My profile')">
    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('profile.update') }}" class="rounded-xl border border-gray-200 bg-white p-6">
            @csrf
            @method('PATCH')
            <h2 class="mb-4 text-sm font-semibold text-gray-800">{{ __('Profile') }}</h2>

            <x-form.input name="name" :label="__('Name')" :value="$user->name" required maxlength="255" />
            <x-form.input name="phone" :label="__('Phone')" :value="$user->phone" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX" />

            <div class="mb-4">
                <p class="mb-2 block text-sm font-medium text-gray-700">{{ __('Email') }}</p>
                <p class="text-sm text-gray-500">{{ $user->email }}</p>
            </div>

            <button type="submit" class="rounded-lg bg-green-900 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">{{ __('Save') }}</button>
        </form>

        <form method="POST" action="{{ route('profile.password') }}" class="rounded-xl border border-gray-200 bg-white p-6">
            @csrf
            @method('PUT')
            <h2 class="mb-4 text-sm font-semibold text-gray-800">{{ __('Change password') }}</h2>

            @foreach (['current_password' => __('Current password'), 'password' => __('New password'), 'password_confirmation' => __('Confirm new password')] as $field => $label)
                <div class="mb-4">
                    <label for="{{ $field }}" class="mb-2 block text-sm font-medium text-gray-700">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="password" required
                        autocomplete="{{ $field === 'current_password' ? 'current-password' : 'new-password' }}"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-green-800 focus:outline-none">
                    @error($field, 'password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <button type="submit" class="rounded-lg bg-green-900 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">{{ __('Change password') }}</button>
        </form>
    </div>
</x-layouts.app>
