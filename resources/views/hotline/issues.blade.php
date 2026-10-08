@php
    $resolutions = ['delivered' => __('Delivered'), 'rescheduled' => __('New date agreed'), 'partial' => __('Partial delivery'), 'returned' => __('Will be returned'), 'exchange_created' => __('Exchange order made'), 'solved' => __('Solved')];
@endphp

<x-layouts.app :heading="$all ? __('Delivery issues') : __('My delivery issues')">
    @if ($issues->isEmpty())
        <x-empty-state :message="__('No open delivery issue.')" />
    @endif

    <div class="grid gap-3 lg:grid-cols-2">
        @foreach ($issues as $i)
            @php($due = \Illuminate\Support\Carbon::parse($i->sla_due_at))
            <div @class(['rounded-xl border bg-white p-4', 'border-red-300' => $due->isPast(), 'border-gray-200' => ! $due->isPast()]) x-data="{ r: null }">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <a href="{{ route('orders.show', $i->order_id) }}" class="font-mono font-semibold hover:underline">{{ $i->order_no }}</a>
                        <span class="text-sm text-gray-600">· {{ $i->ship_name }} · ৳{{ number_format((float) $i->cod_amount) }}</span>
                        <p class="text-sm text-gray-800">{{ ucfirst(str_replace('_', ' ', $i->issue_type)) }}{{ $i->note ? ': '.$i->note : '' }}</p>
                        @if ($i->rider_phone)<a href="tel:{{ $i->rider_phone }}" class="text-xs text-primary">☎ {{ __('Rider') }} {{ $i->rider_phone }}</a>@endif
                        @if ($all)<p class="text-xs text-gray-500">{{ __('Assigned to') }}: {{ $i->moderator ?? '-' }}</p>@endif
                    </div>
                    <x-badge :color="$due->isPast() ? 'red' : 'amber'">{{ $due->isPast() ? __('Overdue :t', ['t' => $due->diffForHumans(null, true)]) : __('Due in :t', ['t' => $due->diffForHumans(null, true)]) }}</x-badge>
                </div>
                <form method="POST" action="{{ route('issues.resolve', $i->id) }}" class="mt-3 space-y-2 border-t border-gray-100 pt-3">
                    @csrf
                    <input type="hidden" name="resolution" :value="r">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($resolutions as $key => $label)
                            <button type="button" @click="r = @js($key)" class="rounded-full border px-2.5 py-1 text-xs" :class="r === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                        @endforeach
                    </div>
                    <input name="note" maxlength="500" placeholder="{{ __('What was agreed') }}" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <p x-show="!r" class="text-xs text-gray-500">{{ __('Pick how it ended to close it.') }}</p>
                    <x-button size="sm" x-bind:disabled="!r">{{ __('Close issue') }}</x-button>
                </form>
            </div>
        @endforeach
    </div>
</x-layouts.app>
