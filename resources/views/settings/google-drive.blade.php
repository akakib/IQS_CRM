@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none')

<x-layouts.app :heading="__('Google Drive')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Call recordings go to "Call Record" and call-log screenshots to "Call SS" in the business Google Drive, within a minute of saving the call. IQS makes its own folders there and can see only the files it puts in them. Files still open from IQS for the caller and managers.') }}</p>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="__('Connection')">
            @if ($drive->isConnected())
                <div class="rounded-lg border border-green-200 bg-primary-soft p-3 text-sm text-primary">
                    <p class="font-semibold">{{ __('Connected') }}</p>
                    <p class="mt-0.5 break-all">{{ $drive->account_email ?? __('Google account') }} · {{ $drive->connected_at?->format('d M Y, h:i A') }}</p>
                </div>
                <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Folders in Drive') }}</p>
                <ul class="mt-1 space-y-0.5 text-sm text-gray-700">
                    <li>{{ $folders['root'] }}</li>
                    <li class="pl-4">{{ $folders['records'] }}</li>
                    <li class="pl-4">{{ $folders['screenshots'] }}</li>
                </ul>
                <p class="mt-4 text-sm text-gray-700">{{ __(':n call files in Drive.', ['n' => number_format($inDrive)]) }}
                    @if ($waiting) {{ __(':n waiting on the server; they move within a few minutes.', ['n' => number_format($waiting)]) }} @endif</p>
                @if ($drive->tested_at)
                    <div class="mt-4 rounded-lg border p-3 text-sm {{ $drive->test_ok ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-700' }}">
                        <p class="font-semibold">{{ $drive->test_ok ? __('Last test passed') : __('Last test failed') }} · {{ $drive->tested_at->format('d M, h:i A') }}</p>
                        <p class="mt-0.5 break-words">{{ $drive->test_message }}</p>
                    </div>
                @endif
                <div class="mt-4 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('settings.google-drive.test') }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <x-button x-bind:disabled="busy"><span x-text="busy ? @js(__('Testing...')) : @js(__('Test upload'))">{{ __('Test upload') }}</span></x-button>
                    </form>
                    <form method="POST" action="{{ route('settings.google-drive.connect') }}">
                        @csrf
                        <x-button variant="secondary">{{ __('Connect again') }}</x-button>
                    </form>
                    <form method="POST" action="{{ route('settings.google-drive.disconnect') }}" id="google-drive-disconnect">
                        @csrf
                        <x-button type="button" variant="danger-outline" x-data @click="$dispatch('open-confirm', { id: 'google-drive-disconnect', form: 'google-drive-disconnect' })">{{ __('Disconnect') }}</x-button>
                    </form>
                </div>
            @elseif ($drive->hasKeys())
                <p class="text-sm text-gray-600">{{ __('Keys are saved. Connect the Google account whose Drive should keep the files.') }}</p>
                <form method="POST" action="{{ route('settings.google-drive.connect') }}" class="mt-4">
                    @csrf
                    <x-button>{{ __('Connect Google Drive') }}</x-button>
                </form>
                <p class="mt-3 text-xs text-gray-500">{{ __('If Google shows "Google hasn\'t verified this app", press Advanced, then Go to iqs.top5way.com. Tick the Drive box on the next page.') }}</p>
            @else
                <p class="text-sm text-gray-600">{{ __('Save the Client ID and Client secret from Google Cloud first.') }}</p>
            @endif
        </x-card>

        <x-card :title="__('Keys from Google Cloud')">
            <form method="POST" action="{{ route('settings.google-drive.save') }}" class="space-y-3">
                @csrf
                <label class="block text-sm font-medium text-gray-700">{{ __('Client ID') }}
                    <input name="client_id" required maxlength="255" value="{{ old('client_id', $drive->client_id) }}" autocomplete="off" class="{{ $input }} mt-1 font-mono">
                </label>
                @error('client_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium text-gray-700">{{ __('Client secret') }}
                    <input name="client_secret" type="password" maxlength="255" autocomplete="new-password" @if (! $drive->client_secret) required @endif
                        placeholder="{{ $drive->client_secret ? __('Saved. Leave empty to keep it.') : '' }}" class="{{ $input }} mt-1 font-mono">
                </label>
                @error('client_secret')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <div>
                    <p class="text-sm font-medium text-gray-700">{{ __('Redirect URI (must match Google Cloud exactly)') }}</p>
                    <p class="mt-1 break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-700">{{ $redirectUri }}</p>
                </div>
                <p class="text-xs text-gray-500">{{ __('Changing the keys disconnects Drive; connect again after saving.') }}</p>
                <x-button>{{ __('Save keys') }}</x-button>
            </form>
        </x-card>
    </div>

    <x-confirm-modal id="google-drive-disconnect" :verb="__('Disconnect')" :message="__('IQS stops putting files in Drive. Files already there stay.')" />
</x-layouts.app>
