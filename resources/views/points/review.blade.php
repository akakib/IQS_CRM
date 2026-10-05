@php
    $flagLabels = ['too_fast_confirm' => __('Confirmed too fast'), 'qa_not_called' => __('Customer says nobody called'), 'qa_wrong_info' => __('Customer details were wrong')];
    $canSeeContact = auth()->user()->canSeeField('customer_contact');
@endphp

<x-layouts.app :heading="__('Points review')">
    <x-tabs :tabs="[
        'flags' => [__('Flags'), route('points.review', ['tab' => 'flags']), $counts['flags']],
        'disputes' => [__('Disputes'), route('points.review', ['tab' => 'disputes']), $counts['disputes']],
        'qa' => [__('Call-back check'), route('points.review', ['tab' => 'qa'])],
    ]" :active="$tab" />

    @if ($tab === 'flags')
        <p class="mb-3 text-sm text-gray-500">{{ __('The system or a call-back check found something odd. Confirm only if the status was faked: that adds the fake-status minus. Nothing is taken until you confirm.') }}</p>
        @forelse ($flags as $f)
            <div class="mb-2 rounded-xl border border-gray-200 bg-white p-3">
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800">{{ $f->name }} · {{ $flagLabels[$f->flag_type] ?? $f->flag_type }}</p>
                        <p class="text-xs text-gray-500">
                            @if ($f->order_no)<a href="{{ route('orders.show', $f->order_id) }}" class="font-mono text-primary hover:underline">{{ $f->order_no }}</a> · @endif
                            {{ $f->details }} · {{ \Illuminate\Support\Carbon::parse($f->created_at)->format('d M, g:i A') }}
                        </p>
                    </div>
                    <form method="POST" action="{{ route('points.review.flag', $f->id) }}" class="flex shrink-0 flex-wrap items-center gap-2">
                        @csrf
                        <input name="note" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="w-40 rounded-lg border border-gray-300 px-2 py-1 text-sm">
                        <x-button size="sm" name="decision" value="dismissed" variant="secondary">{{ __('Dismiss') }}</x-button>
                        <x-button size="sm" name="decision" value="confirmed" variant="danger">{{ __('Confirm fake') }}</x-button>
                    </form>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('Nothing to review.') }}</div>
        @endforelse
        @if ($flags->hasPages())<div class="mt-4">{{ $flags->links() }}</div>@endif
    @elseif ($tab === 'disputes')
        @forelse ($disputes as $d)
            @php($rule = json_decode($d->rule_snapshot, true))
            <div class="mb-2 rounded-xl border border-gray-200 bg-white p-3">
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800">{{ $d->name }} · {{ $rule['name'] ?? $d->trigger_key }} <span class="text-red-700">{{ (float) $d->points }}</span></p>
                        <p class="text-xs text-gray-500">
                            @if ($d->order_no)<a href="{{ route('orders.show', $d->order_id) }}" class="font-mono text-primary hover:underline">{{ $d->order_no }}</a> · @endif
                            "{{ $d->dispute_note }}"
                        </p>
                    </div>
                    <form method="POST" action="{{ route('points.review.dispute', $d->id) }}" class="flex shrink-0 gap-2">
                        @csrf
                        <x-button size="sm" name="decision" value="rejected" variant="secondary">{{ __('Keep the point') }}</x-button>
                        <x-button size="sm" name="decision" value="upheld">{{ __('Remove the point') }}</x-button>
                    </form>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No open disputes.') }}</div>
        @endforelse
        @if ($disputes->hasPages())<div class="mt-4">{{ $disputes->links() }}</div>@endif
    @else
        <p class="mb-3 text-sm text-gray-500">{{ __('Up to 5 orders per person, confirmed in the last 7 days. Call the customer and ask if someone called them before the order was confirmed. The list stays the same all week.') }}</p>
        @forelse ($sample->groupBy('agent') as $agent => $orders)
            <h2 class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $agent }}</h2>
            @foreach ($orders as $o)
                <div class="mb-2 rounded-xl border border-gray-200 bg-white p-3">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div class="min-w-0 text-sm">
                            <a href="{{ route('orders.show', $o->id) }}" class="font-mono text-primary hover:underline">{{ $o->order_no }}</a>
                            · {{ $o->ship_name }}
                            · <span class="font-mono">{{ $canSeeContact ? $o->ship_phone : substr($o->ship_phone, 0, 3).'*****'.substr($o->ship_phone, -3) }}</span>
                            · ৳{{ number_format((float) $o->grand_total) }}
                        </div>
                        <form method="POST" action="{{ route('points.review.qa', $o->id) }}" class="flex shrink-0 flex-wrap items-center gap-2">
                            @csrf
                            <input name="note" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="w-36 rounded-lg border border-gray-300 px-2 py-1 text-sm">
                            <x-button size="sm" name="result" value="call_verified">{{ __('Was called') }}</x-button>
                            <x-button size="sm" name="result" value="not_called" variant="danger">{{ __('Not called') }}</x-button>
                            <x-button size="sm" name="result" value="wrong_info" variant="secondary">{{ __('Wrong info') }}</x-button>
                        </form>
                    </div>
                </div>
            @endforeach
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No orders to check this week.') }}</div>
        @endforelse
    @endif
</x-layouts.app>
