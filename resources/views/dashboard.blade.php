<x-layouts.app :heading="__('Dashboard')">
    <p class="mb-4 text-sm text-gray-600">{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</p>

    @if ($myWorking->isNotEmpty())
        <div class="mb-4 rounded-xl border border-green-200 bg-primary-soft p-3 text-sm text-primary">
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
</x-layouts.app>
