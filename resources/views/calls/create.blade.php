<x-layouts.app :heading="__('New call list')">
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('calls.lists') }}" class="mb-3 inline-block text-sm text-primary hover:underline">{{ __('Back to call lists') }}</a>

        @if (! $imports)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
                <p class="text-sm font-medium text-gray-700">{{ __('Import a file first') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('A call list is made from the customers of an imported file.') }}</p>
                @can('customers.import')<x-button :href="route('customers.history')" class="mt-3">{{ __('Import order history') }}</x-button>@endcan
            </div>
        @else
            <form method="POST" action="{{ route('calls.lists.store') }}" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('Customers from this file') }}</label>
                    <x-simple-select name="import_id" :options="$imports" :value="old('import_id', array_key_first($imports))" full-width searchable class="mt-1" />
                    @error('import_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Which orders') }}</label>
                        <x-simple-select name="status" :options="['' => __('All'), 'delivered' => __('Delivered'), 'returned' => __('Returned'), 'cancelled' => __('Cancelled'), 'refunded' => __('Refunded')]" :value="old('status', 'delivered')" full-width class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Ordered from (optional)') }}</label>
                        <input type="date" name="from" value="{{ old('from') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-base focus:border-primary focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Ordered to (optional)') }}</label>
                        <input type="date" name="to" value="{{ old('to') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 text-base focus:border-primary focus:outline-none">
                        @error('to')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="-mt-2 text-xs text-gray-500">{{ __('One line per customer (not per order). Anyone who asked not to be called is left out.') }}</p>

                @include('calls._form', ['list' => null, 'callers' => $callers, 'assigned' => []])

                <x-button class="w-full sm:w-auto">{{ __('Make the list') }}</x-button>
            </form>
        @endif
    </div>
</x-layouts.app>
