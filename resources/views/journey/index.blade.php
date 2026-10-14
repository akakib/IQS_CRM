@php use App\Support\Permissions\Mask; @endphp

<x-layouts.app :heading="__('Customer journey')">
    <div class="mx-auto max-w-3xl">
        {{-- A phone number (any way it is typed) opens the customer straight away; a name lists the matches. --}}
        <form method="GET" action="{{ route('journey.index') }}" class="mb-5 rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <div class="flex gap-2">
                <div class="relative min-w-0 flex-1">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                    <input type="search" name="q" value="{{ $q }}" autofocus autocomplete="off" placeholder="{{ __('Phone, CN or customer name') }}"
                        class="w-full rounded-xl border border-gray-300 py-3.5 pl-11 pr-4 text-base focus:border-primary focus:outline-none">
                </div>
                <x-button class="shrink-0 px-5">{{ __('Search') }}</x-button>
            </div>
            <p class="mt-2 text-xs text-gray-500">{{ __('Any form works: 01712345678, +880 1712-345678, 1712345678 or Bangla digits.') }}</p>
        </form>

        @if ($q === '')
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                <p class="text-sm font-medium text-gray-700">{{ __('See a customer\'s whole story') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('Every order and call, what they bought, from where, and how much was sold, cancelled or returned.') }}</p>
            </div>
        @elseif ($tooShort)
            <x-empty-state :message="__('Type at least 3 characters.')" />
        @elseif ($customers->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
                <p class="text-sm font-medium text-gray-700">{{ __('No customer found for :q', ['q' => $q]) }}</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($customers as $c)
                    <a href="{{ route('journey.show', $c) }}" class="flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-4 hover:border-primary">
                        <div class="min-w-0">
                            <p class="truncate text-base font-medium text-gray-900">{{ $c->name }}</p>
                            <p class="font-mono text-sm text-gray-500">{{ Mask::value($c->primary_phone, 'customer_contact') }}</p>
                        </div>
                        <p class="shrink-0 text-right text-xs text-gray-500">{{ trans_choice(':count order|:count orders', $c->orders_count, ['count' => $c->orders_count]) }}<br>{{ __(':d delivered', ['d' => $c->delivered_count]) }}</p>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
