@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
@endphp

<x-layouts.app :heading="__('Courier accounts')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Steadfast API keys (merchant panel > API). Saved encrypted; only the first and last letters are ever shown again. To change a key, type the new one; leave it empty to keep the saved one.') }}</p>

    @unless ($liveBooking)
        <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('This is the test server: parcels are booked with the test courier, never with Steadfast, even with keys saved. "Check connection" still talks to Steadfast (it only reads the balance).') }}</p>
    @endunless

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            @forelse ($accounts as $a)
                <x-card>
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <p class="text-base font-semibold text-gray-900">{{ $a->name }}</p>
                        <x-badge color="gray">{{ ucfirst($a->courier) }}</x-badge>
                        @if ($a->is_default)<x-badge color="green">{{ __('Used for booking') }}</x-badge>@endif
                        @unless ($a->is_active)<x-badge color="red">{{ __('Off') }}</x-badge>@endunless
                    </div>
                    <dl class="mb-3 grid gap-2 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs uppercase text-gray-500">{{ __('API key') }}</dt><dd class="font-mono text-gray-800">{{ \App\Models\CourierAccount::mask($a->api_key) }}</dd></div>
                        <div><dt class="text-xs uppercase text-gray-500">{{ __('Secret key') }}</dt><dd class="font-mono text-gray-800">{{ \App\Models\CourierAccount::mask($a->secret_key) }}</dd></div>
                        <div><dt class="text-xs uppercase text-gray-500">{{ __('Webhook auth token') }}</dt><dd class="font-mono text-gray-800">{{ $a->webhook_token ? \App\Models\CourierAccount::mask($a->webhook_token) : __('Not set here') }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs uppercase text-gray-500">{{ __('Last check') }}</dt>
                            <dd class="text-gray-700">{{ $a->last_checked_at ? $a->last_checked_at->format('d M, g:i A').' · '.$a->last_check_result : __('Not checked yet') }}</dd></div>
                    </dl>

                    <div class="flex flex-wrap gap-2" x-data="{ edit: false }">
                        <form method="POST" action="{{ route('settings.couriers.check', $a) }}">@csrf
                            <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Check connection') }}</button>
                        </form>
                        <form method="POST" action="{{ route('settings.couriers.test-webhook', $a) }}">@csrf
                            <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Test webhook') }}</button>
                        </form>
                        <button type="button" @click="edit = !edit" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" x-text="edit ? @js(__('Cancel')) : @js(__('Edit'))"></button>

                        <form x-show="edit" x-cloak method="POST" action="{{ route('settings.couriers.update', $a) }}" class="mt-2 w-full space-y-2 border-t border-gray-100 pt-3">
                            @csrf @method('PUT')
                            <label class="block text-sm text-gray-700">{{ __('Name') }}<input name="name" value="{{ $a->name }}" required maxlength="100" class="{{ $input }} mt-1"></label>
                            <label class="block text-sm text-gray-700">{{ __('New API key') }}<input name="api_key" autocomplete="off" placeholder="{{ __('Leave empty to keep the saved key') }}" class="{{ $input }} mt-1 font-mono"></label>
                            <label class="block text-sm text-gray-700">{{ __('New secret key') }}<input name="secret_key" type="password" autocomplete="new-password" placeholder="{{ __('Leave empty to keep the saved key') }}" class="{{ $input }} mt-1 font-mono"></label>
                            <label class="block text-sm text-gray-700">{{ __('New webhook auth token') }}<input name="webhook_token" type="password" autocomplete="new-password" placeholder="{{ __('Same token as the Steadfast webhook page. Empty keeps the saved one') }}" class="{{ $input }} mt-1 font-mono"></label>
                            <div class="flex flex-wrap gap-4 text-sm text-gray-700">
                                <label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($a->is_active) class="rounded border-gray-300 text-primary"> {{ __('On') }}</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="is_default" value="1" @checked($a->is_default) class="rounded border-gray-300 text-primary"> {{ __('Use this account for booking') }}</label>
                            </div>
                            <x-button>{{ __('Save') }}</x-button>
                        </form>
                    </div>
                </x-card>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No courier account yet. Add the Steadfast keys with the "Add a Steadfast account" form.') }}</div>
            @endforelse

            <x-card :title="__('Steadfast → IQS (delivery status webhook)')">
                <p class="mb-2 text-sm text-gray-600">{{ __('In the Steadfast panel (Webhook page), set this callback URL and an auth token. Type the same token in this account (Edit > New webhook auth token), then press Test webhook.') }}</p>
                <p class="mb-3 break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm">{{ $webhookUrl }}</p>
                @php
                    $when = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->timezone(config('app.timezone'))->format('d M, g:i A') : null;
                @endphp
                <dl class="grid gap-2 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs uppercase text-gray-500">{{ __('Last accepted call') }}</dt><dd class="text-gray-800">{{ $when($webhookAccepted) ?? __('None yet') }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500">{{ __('Last real status update') }}</dt><dd class="text-gray-800">{{ $when($lastEvent) ?? __('None yet') }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500">{{ __('Last refused call') }}</dt><dd class="{{ $webhookRejected && $webhookRejected > (string) $webhookAccepted ? 'font-medium text-red-600' : 'text-gray-800' }}">{{ $when($webhookRejected) ?? __('None') }}</dd></div>
                </dl>
                @if ($webhookRejected && $webhookRejected > (string) $webhookAccepted)
                    <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ __('Steadfast called but the token did not match. Copy the auth token from the Steadfast webhook page into this account again.') }}</p>
                @endif
                <p class="mt-2 text-xs text-gray-500">{{ __('Steadfast has no test button: "Last real status update" fills in when any parcel of this Steadfast account changes status.') }}</p>
            </x-card>
        </div>

        <x-card :title="__('Add a Steadfast account')">
            <form method="POST" action="{{ route('settings.couriers.store') }}" class="space-y-2">
                @csrf
                <label class="block text-sm text-gray-700">{{ __('Name') }}<input name="name" value="{{ old('name', 'Steadfast') }}" required maxlength="100" class="{{ $input }} mt-1"></label>
                <label class="block text-sm text-gray-700">{{ __('API key') }}<input name="api_key" required autocomplete="off" class="{{ $input }} mt-1 font-mono"></label>
                <label class="block text-sm text-gray-700">{{ __('Secret key') }}<input name="secret_key" type="password" required autocomplete="new-password" class="{{ $input }} mt-1 font-mono"></label>
                <label class="block text-sm text-gray-700">{{ __('Webhook auth token (optional)') }}<input name="webhook_token" type="password" autocomplete="new-password" placeholder="{{ __('Same token as the Steadfast webhook page') }}" class="{{ $input }} mt-1 font-mono"></label>
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="is_default" value="1" checked class="rounded border-gray-300 text-primary"> {{ __('Use this account for booking') }}</label>
                @if ($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                <x-button>{{ __('Save account') }}</x-button>
            </form>
        </x-card>
    </div>
</x-layouts.app>
