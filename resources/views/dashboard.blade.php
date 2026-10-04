<x-layouts.app :heading="__('Dashboard')">
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <p class="text-sm text-gray-600">{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</p>
    </div>
</x-layouts.app>
