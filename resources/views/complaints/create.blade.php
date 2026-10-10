@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-green-800 focus:outline-none';
    $sources = ['phone' => __('Phone call'), 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'website' => __('Website'), 'rider' => __('Rider / hotline'), 'other' => __('Other')];
@endphp

<x-layouts.app :heading="__('New complaint')">
    <form method="POST" action="{{ route('complaints.store') }}" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-3">
        @csrf
        <div class="space-y-6 xl:col-span-2">
            <x-card :title="__('Who and what')">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Order number') }}</label>
                        <input name="order_no" value="{{ old('order_no', $order?->order_no) }}" maxlength="30" placeholder="IQ…" class="{{ $input }} font-mono uppercase">
                        <p class="mt-1 text-xs text-gray-500">{{ __('Leave empty for a complaint that is not about one order.') }}</p>
                        @error('order_no')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Customer phone') }}</label>
                        <input name="customer_phone" value="{{ old('customer_phone', $order?->ship_phone) }}" maxlength="20" inputmode="tel" placeholder="01…" class="{{ $input }} font-mono">
                        @error('customer_phone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Customer name') }}</label>
                        <input name="customer_name" value="{{ old('customer_name', $order?->ship_name) }}" maxlength="150" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Came in through') }}</label>
                        <x-simple-select name="source" :options="$sources" :value="old('source', 'phone')" full-width class="w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Complaint type') }}</label>
                        <x-simple-select name="category_id" :options="['' => __('Choose…')] + $categories" :value="old('category_id', '')" full-width class="w-full" />
                        @error('category_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('What happened') }}</label>
                        <textarea name="description" rows="4" required maxlength="2000" class="{{ $input }}" placeholder="{{ __('In the customer’s words: what was wrong, what they want.') }}">{{ old('description') }}</textarea>
                        @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-card>

            <x-card :title="__('Photos')" :subtitle="__('Up to 6 pictures, 4 MB each. The customer’s photo of the parcel or product.')">
                <input type="file" name="photos[]" multiple accept="image/*" class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-green-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-green-900">
                @error('photos')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('photos.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </x-card>
        </div>

        <div class="space-y-6">
            @if ($staff)
                <x-card :title="__('Assigned to')" :subtitle="__('Empty = the order’s moderator, or you.')">
                    <x-simple-select name="assigned_to" :options="['' => __('Automatic')] + $staff" :value="old('assigned_to', '')" full-width class="w-full" />
                </x-card>
            @endif
            <x-card>
                <x-button class="w-full">{{ __('Open complaint') }}</x-button>
                <a href="{{ route('complaints.index') }}" class="mt-2 block text-center text-sm text-gray-500 hover:underline">{{ __('Cancel') }}</a>
            </x-card>
        </div>
    </form>
</x-layouts.app>
