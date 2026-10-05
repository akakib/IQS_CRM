<x-layouts.guest :title="__('Log in')">
    <form method="POST" action="{{ route('login') }}" x-data="{ busy: false }" @submit="busy = true">
        @csrf

        <x-form.input name="email" type="email" :label="__('Email')" required autofocus autocomplete="username" placeholder="your@email.com" />
        <x-form.input name="password" type="password" :label="__('Password')" required autocomplete="current-password" />

        <div class="mb-6 flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded border-gray-300 text-primary">
                {{ __('Remember me') }}
            </label>
            <a href="{{ route('password.request') }}" class="text-sm text-primary hover:underline">{{ __('Forgot password?') }}</a>
        </div>

        <button type="submit" :disabled="busy"
            class="w-full rounded-lg bg-primary px-6 py-2.5 text-sm font-medium text-white hover:bg-primary-dark disabled:opacity-60">
            {{ __('Log in') }}
        </button>
    </form>
</x-layouts.guest>
