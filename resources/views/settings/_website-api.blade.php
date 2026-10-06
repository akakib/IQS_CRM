{{-- Website API keys (WooCommerce > Settings > Advanced > REST API keys), encrypted;
     Check connection counts the products; Pull products starts a full import.
     Included by settings/integrations.blade.php with $websiteAccount. --}}
@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $a = $websiteAccount;
@endphp
<x-card :title="__('Website API (pull products)')">
    <p class="mb-3 text-sm text-gray-600">{{ __('WooCommerce > Settings > Advanced > REST API keys > Add key (Read is enough). Paste the Consumer key and Consumer secret here. The website is the master: names, prices, stock status, images and SEO come from it; unit, pack size, barcode and cost price stay as set in IQS.') }}</p>
    @if ($a)
        <dl class="mb-3 grid gap-2 text-sm sm:grid-cols-2">
            <div><dt class="text-xs uppercase text-gray-500">{{ __('Website') }}</dt><dd class="break-all text-gray-800">{{ $a->url }}</dd></div>
            <div><dt class="text-xs uppercase text-gray-500">{{ __('Consumer key') }}</dt><dd class="font-mono text-gray-800">{{ \App\Models\CourierAccount::mask($a->consumer_key) }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs uppercase text-gray-500">{{ __('Last check') }}</dt>
                <dd class="{{ str_starts_with((string) $a->last_check_result, 'Connected') ? 'text-green-800' : 'text-gray-700' }}">{{ $a->last_checked_at ? $a->last_checked_at->format('d M, g:i A').' · '.$a->last_check_result : __('Not checked yet') }}</dd></div>
        </dl>
        <div class="flex flex-wrap gap-2" x-data="{ edit: false }">
            <form method="POST" action="{{ route('settings.website-api.check', $a) }}">@csrf
                <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Check connection') }}</button>
            </form>
            <form method="POST" action="{{ route('settings.website-api.pull') }}" id="website-pull">@csrf
                <button type="button" class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary-dark"
                    @click="$dispatch('open-confirm', { id: 'website-pull-confirm', form: 'website-pull' })">{{ __('Pull products from the website') }}</button>
            </form>
            <x-confirm-modal id="website-pull-confirm" :verb="__('Pull now')" :danger="false"
                :message="__('Every product on the website is read and saved here (matched by website id, then SKU). Names, prices, stock status, images and SEO here are replaced by the website\'s. Nothing is sent to the website.')" />
            <button type="button" @click="edit = !edit" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" x-text="edit ? @js(__('Cancel')) : @js(__('Edit'))"></button>

            <form x-show="edit" x-cloak method="POST" action="{{ route('settings.website-api.update', $a) }}" class="mt-2 w-full space-y-2 border-t border-gray-100 pt-3">
                @csrf @method('PUT')
                <label class="block text-sm text-gray-700">{{ __('Name') }}<input name="name" value="{{ $a->name }}" required maxlength="100" class="{{ $input }} mt-1"></label>
                <label class="block text-sm text-gray-700">{{ __('Website URL') }}<input name="url" value="{{ $a->url }}" required maxlength="255" class="{{ $input }} mt-1"></label>
                <label class="block text-sm text-gray-700">{{ __('New consumer key') }}<input name="consumer_key" autocomplete="off" placeholder="{{ __('Leave empty to keep the saved key') }}" class="{{ $input }} mt-1 font-mono"></label>
                <label class="block text-sm text-gray-700">{{ __('New consumer secret') }}<input name="consumer_secret" type="password" autocomplete="new-password" placeholder="{{ __('Leave empty to keep the saved key') }}" class="{{ $input }} mt-1 font-mono"></label>
                <x-button>{{ __('Save') }}</x-button>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('settings.website-api.store') }}" class="space-y-2">
            @csrf
            <label class="block text-sm text-gray-700">{{ __('Name') }}<input name="name" value="{{ old('name', settings('store.name') ?: 'Website') }}" required maxlength="100" class="{{ $input }} mt-1"></label>
            <label class="block text-sm text-gray-700">{{ __('Website URL') }}<input name="url" value="{{ old('url') }}" required maxlength="255" placeholder="https://example.com" class="{{ $input }} mt-1"></label>
            <label class="block text-sm text-gray-700">{{ __('Consumer key') }}<input name="consumer_key" required autocomplete="off" class="{{ $input }} mt-1 font-mono"></label>
            <label class="block text-sm text-gray-700">{{ __('Consumer secret') }}<input name="consumer_secret" type="password" required autocomplete="new-password" class="{{ $input }} mt-1 font-mono"></label>
            @if ($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
            <x-button>{{ __('Save website API') }}</x-button>
        </form>
    @endif
</x-card>
