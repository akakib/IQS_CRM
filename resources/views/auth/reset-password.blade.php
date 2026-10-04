<x-layouts.guest :title="__('Set new password')">
    <form method="POST" action="{{ route('password.update') }}" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-form.input name="email" type="email" :label="__('Email')" :value="$email" required autocomplete="username" />
        <x-form.input name="password" type="password" :label="__('New password')" required autofocus autocomplete="new-password" />
        <x-form.input name="password_confirmation" type="password" :label="__('Confirm new password')" required autocomplete="new-password" />

        <button type="submit" :disabled="busy"
            class="w-full rounded-lg bg-green-900 px-6 py-2.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-60">
            {{ __('Save new password') }}
        </button>
    </form>
</x-layouts.guest>
