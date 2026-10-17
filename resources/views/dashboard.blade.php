<x-layouts.app :heading="__('Dashboard')">
    <p class="mb-4 text-sm text-gray-600">{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</p>

    @if ($myWorking->isNotEmpty())
        <div class="mb-4 rounded-xl border border-green-200 bg-primary-soft p-3 text-sm text-primary">
            {{ __('You are working on:') }}
            @foreach ($myWorking as $o)
                <a href="{{ route('desk.notice', $o->id) }}" class="ml-1 font-mono font-medium underline">{{ $o->order_no }}</a>
            @endforeach
        </div>
    @endif

    @if ($outOfStock)
        {{-- Out of stock, first thing: tick what came in (one or many) and press Back in stock. No counts asked. --}}
        <div id="out-of-stock" class="mb-4 rounded-2xl border border-red-200 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-gray-900">{{ __('Out of stock') }} <span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ number_format($outOfStock->total()) }}</span></p>
                <form method="GET" action="{{ route('dashboard') }}#out-of-stock" class="flex gap-2">
                    <input type="search" name="oos" value="{{ request('oos') }}" placeholder="{{ __('Find an item') }}" autocomplete="off" class="h-10 w-56 max-w-full rounded-lg border border-gray-300 px-3 text-base sm:text-sm focus:border-primary focus:outline-none">
                </form>
            </div>
            @if ($outOfStock->isEmpty())
                <p class="rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">{{ request('oos') ? __('Nothing out of stock matches.') : __('Nothing is out of stock.') }}</p>
            @else
                <x-list.selectable :ids="$outOfStock->pluck('id')" persist="dashboard-oos">
                    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                        @foreach ($outOfStock as $v)
                            <div class="flex items-center gap-3 px-3 py-2.5">
                                <x-list.check :id="$v->id" />
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-gray-900">{{ $v->product?->name }}{{ $v->name !== 'Default' ? ' · '.$v->name : '' }}</span>
                                    <span class="block text-xs text-gray-500">{{ $v->oos_marked_at ? __('Out since :d', ['d' => $v->oos_marked_at->format('d M')]) : '' }}</span></span>
                                <form method="POST" action="{{ route('products.availability.update') }}">
                                    @csrf
                                    <input type="hidden" name="ids[]" value="{{ $v->id }}"><input type="hidden" name="status" value="in_stock">
                                    <button class="inline-flex min-h-8 shrink-0 items-center rounded-lg border border-green-600 px-3 text-xs font-semibold text-green-700 hover:bg-green-50">{{ __('Back in stock') }}</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                    <x-slot:actions>
                        <form method="POST" action="{{ route('products.availability.update') }}">
                            @csrf
                            <x-list.selected-inputs /><input type="hidden" name="status" value="in_stock">
                            <button class="rounded-lg bg-green-700 px-3 py-1.5 text-xs font-semibold hover:bg-green-600">{{ __('Back in stock') }}</button>
                        </form>
                    </x-slot:actions>
                </x-list.selectable>
                <div class="mt-3">{{ $outOfStock->links() }}</div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ($tiles as [$label, $value, $href, $trend])
            <x-stat-tile :label="$label" :value="number_format($value)" :href="$href" :trend="$trend" />
        @endforeach
        <x-stat-tile :label="__('My points this month')" :value="($myPoints > 0 ? '+' : '').rtrim(rtrim(number_format($myPoints, 2), '0'), '.')" :href="route('points.mine')" :trend="$myPoints < 0 ? 'down' : null" />
    </div>
</x-layouts.app>
