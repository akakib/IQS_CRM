@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none')

<x-layouts.app :heading="__('Categories')">
    <x-products.subnav active="categories" />

    <x-card :title="__('Add category')" class="mb-4 max-w-2xl">
        <form method="POST" action="{{ route('categories.store') }}" class="flex flex-col gap-2 md:flex-row md:items-start">
            @csrf
            <div class="flex-1">
                <input name="name" value="{{ old('name') }}" required maxlength="150" placeholder="{{ __('Name, e.g. Dates') }}" class="{{ $input }} w-full">
                @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <x-simple-select name="parent_id" :options="['' => __('Top level')] + $parentOptions" value="" />
            <x-button>{{ __('Add') }}</x-button>
        </form>
    </x-card>

    @if ($categories->isEmpty())
        <x-empty-state :message="__('No categories yet.')" />
    @else
        <div class="max-w-2xl divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
            @foreach ($categories as $category)
                @php($formId = 'delete-category-'.$category->id)
                <div class="flex flex-col gap-2 p-3 md:flex-row md:items-center" x-data="{ editing: false }">
                    <div x-show="!editing" class="flex-1">
                        <p class="text-sm font-medium text-gray-800">{{ $category->name }}</p>
                        <p class="text-xs text-gray-500">{{ $category->parent_id ? __('in :p', ['p' => $parentOptions[$category->parent_id] ?? '-']).' · ' : '' }}{{ trans_choice(':count product|:count products', $category->products_count, ['count' => $category->products_count]) }}</p>
                    </div>
                    <form x-show="editing" x-cloak method="POST" action="{{ route('categories.update', $category) }}" class="flex flex-1 flex-col gap-2 md:flex-row">
                        @csrf
                        @method('PUT')
                        <input name="name" value="{{ $category->name }}" required maxlength="150" class="{{ $input }} flex-1">
                        <x-simple-select name="parent_id" :options="['' => __('Top level')] + collect($parentOptions)->except($category->id)->all()" :value="(string) ($category->parent_id ?? '')" />
                        <x-button size="sm">{{ __('Save') }}</x-button>
                    </form>
                    <div class="flex items-center gap-3">
                        <button type="button" @click="editing = !editing" class="text-sm text-green-900 hover:underline" x-text="editing ? @js(__('Cancel')) : @js(__('Edit'))"></button>
                        <form id="{{ $formId }}" method="POST" action="{{ route('categories.destroy', $category) }}">
                            @csrf
                            @method('DELETE')
                            <button type="button" class="text-sm text-red-600 hover:underline"
                                @click="$dispatch('open-confirm', { id: 'delete-category', form: @js($formId), label: @js($category->name) })">{{ __('Delete') }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-confirm-modal id="delete-category" :message="__('Its products stay, just without this category.')" />
</x-layouts.app>
