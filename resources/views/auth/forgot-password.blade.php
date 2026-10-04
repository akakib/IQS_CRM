<x-layouts.guest :title="__('Forgot password')">
    <p class="mb-4 text-sm text-gray-600">{{ __('Enter your email and we will send you a link to set a new password.') }}</p>

    <form method="POST" action="{{ route('password.email') }}" x-data="{ busy: false }" @submit="busy = true">
        @csrf

        <x-form.input name="email" type="email" :label="__('Email')" required autofocus autocomplete="username" />

        <button type="submit" :disabled="busy"
            class="w-full rounded-lg bg-green-900 px-6 py-2.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-60">
            {{ __('Send reset link') }}
        </button>
    </form>

    <p class="mt-4 text-center text-sm"><a href="{{ route('login') }}" class="text-green-900 hover:underline">{{ __('Back to log in') }}</a></p>
</x-layouts.guest>
