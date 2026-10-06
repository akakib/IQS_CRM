<x-layouts.app :heading="__('Import from website')">
    <x-products.subnav active="import" />

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="__('Pull from the website')">
            <p class="mb-3 text-sm text-gray-600">{{ __('Reads every product straight from the website through its API: no file needed, and it can be run again any time to catch up. Product changes on the website also arrive by themselves through the product webhooks.') }}</p>
            @if ($websiteApi)
                <form method="POST" action="{{ route('settings.website-api.pull') }}" id="website-pull">@csrf
                    <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark"
                        @click="$dispatch('open-confirm', { id: 'website-pull-confirm', form: 'website-pull' })">{{ __('Pull products from the website') }}</button>
                </form>
                <x-confirm-modal id="website-pull-confirm" :verb="__('Pull now')" :danger="false"
                    :message="__('Every product on the website is read and saved here (matched by website id, then SKU). Names, prices, stock status, images and SEO here are replaced by the website\'s. Nothing is sent to the website.')" />
            @else
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('Save the website API keys first:') }} <a href="{{ route('settings.integrations') }}" class="font-medium underline">{{ __('Settings > Website connection') }}</a></p>
            @endif
        </x-card>

        <x-card :title="__('Upload a WooCommerce product export')">
            <ol class="mb-4 list-inside list-decimal space-y-1 text-sm text-gray-600">
                <li>{{ __('WordPress admin > Products > Export.') }}</li>
                <li>{{ __('Export all columns, all product types, and tick "Export custom meta" (brings SEO titles and descriptions).') }}</li>
                <li>{{ __('Upload the CSV here.') }}</li>
            </ol>
            <p class="mb-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">
                {{ __('Products are matched by their website id, then by SKU. Names, descriptions, SEO, categories, images and online prices from the file replace the ones here. Nothing is sent back to the website by an import.') }}
            </p>
            <form method="POST" action="{{ route('products.import.store') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv" required class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm">
                @error('file')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <x-button>{{ __('Upload and start') }}</x-button>
            </form>
        </x-card>

        <x-card :title="__('Recent imports')">
            @forelse ($imports as $i)
                <a href="{{ route('products.import.show', $i->id) }}" class="flex items-center justify-between gap-2 border-b border-gray-50 py-2 text-sm last:border-0 hover:bg-gray-50">
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-gray-800">{{ $i->source === 'api' ? __('Website API') : $i->file_name }}</span>
                        <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($i->created_at)->format('d M Y, g:i A') }} · {{ $i->user }} · {{ __(':c new, :u updated, :s skipped', ['c' => $i->created_count, 'u' => $i->updated_count, 's' => $i->skipped_count]) }}</span>
                    </span>
                    <x-badge :color="['done' => 'green', 'failed' => 'red', 'running' => 'blue', 'pending' => 'gray'][$i->status]">{{ ucfirst($i->status) }}</x-badge>
                </a>
            @empty
                <p class="text-sm text-gray-400">{{ __('No imports yet.') }}</p>
            @endforelse
        </x-card>
    </div>
</x-layouts.app>
