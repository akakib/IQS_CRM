<x-layouts.app :heading="__('Dashboard')">
    <p class="mb-4 text-sm text-gray-600">{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</p>

    @if ($myWorking->isNotEmpty())
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-900">
            {{ __('You are working on:') }}
            @foreach ($myWorking as $o)
                <a href="{{ route('orders.show', $o->id) }}" class="ml-1 font-mono font-medium underline">{{ $o->order_no }}</a>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ($tiles as [$label, $value, $href, $trend])
            <x-stat-tile :label="$label" :value="number_format($value)" :href="$href" :trend="$trend" />
        @endforeach
        <x-stat-tile :label="__('My points this month')" :value="($myPoints > 0 ? '+' : '').rtrim(rtrim(number_format($myPoints, 2), '0'), '.')" :href="route('points.mine')" :trend="$myPoints < 0 ? 'down' : null" />
    </div>

    @if ($chart)
        <x-card :title="__('Last 14 days')" :subtitle="__('Orders placed, completed and cancelled per day')" class="mt-4">
            <x-slot:actions><a href="{{ route('reports.sales') }}" class="text-sm font-medium text-green-900 hover:underline">{{ __('Sales report') }} →</a></x-slot:actions>
            <x-line-chart :rows="$chart['rows']" :series="['placed' => [__('Placed'), '#1f6f43'], 'completed' => [__('Completed'), '#2563eb'], 'cancelled' => [__('Cancelled'), '#dc2626']]" :height="180" />
        </x-card>
    @endif
</x-layouts.app>
