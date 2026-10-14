@php
    use App\Http\Controllers\CustomerCallController;
    use App\Models\Order;
    use App\Support\Permissions\Mask;
    $tk = fn ($n) => '৳'.number_format((float) $n);
    $palette = ['web' => '#16a34a', 'messenger' => '#2563eb', 'whatsapp' => '#22c55e', 'comments' => '#6366f1', 'instagram' => '#db2777',
        'tiktok' => '#111827', 'telegram' => '#0ea5e9', 'phone' => '#f59e0b', 'b2b' => '#7c3aed', 'other' => '#9ca3af'];
    $kind = ['delivered' => ['green', '#16a34a'], 'returned' => ['red', '#dc2626'], 'cancelled' => ['red', '#dc2626'], 'open' => ['blue', '#2563eb']];
    $lostN = $stats['returned'][0] + $stats['cancelled'][0];
    $lostAmt = $stats['returned'][1] + $stats['cancelled'][1];
    $pastKind = ['delivered' => ['#16a34a', 'green', 'Delivered'], 'cancelled' => ['#dc2626', 'red', 'Cancelled'], 'returned' => ['#dc2626', 'red', 'Returned'],
        'refunded' => ['#dc2626', 'amber', 'Refunded'], 'pending' => ['#9ca3af', 'gray', 'Not finished']];
@endphp

<x-layouts.app :heading="__('Customer journey')">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('journey.index') }}" class="mb-3 inline-block text-sm text-primary hover:underline">{{ __('Search another customer') }}</a>

        <div class="mb-3 rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $customer->name }}</h2>
                    <p class="text-sm text-gray-500">
                        <span class="font-mono">{{ Mask::value($customer->primary_phone, 'customer_contact') }}</span>
                        @if ($area) · {{ $area }}@endif
                        @if ($stats['first']) · {{ __('customer since :d', ['d' => $stats['first']->format('d M Y')]) }}@endif
                    </p>
                </div>
                <a href="{{ route('customers.show', $customer) }}" class="text-sm text-primary hover:underline">{{ __('Customer details') }}</a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Delivered sales') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900">{{ $tk($stats['sold']) }}</p></div>
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Orders') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900">{{ $stats['orders'] }} <span class="text-xs font-normal text-gray-500">{{ __(':d delivered', ['d' => $stats['delivered']]) }}</span></p></div>
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Cancel / return') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums {{ $lostN ? 'text-red-700' : 'text-gray-900' }}">{{ $lostN }} · {{ $tk($lostAmt) }}</p></div>
                <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">{{ __('Orders every') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900">{{ $stats['gap'] === null ? '-' : trans_choice(':count day|:count days', $stats['gap'], ['count' => $stats['gap']]) }}</p></div>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                {{ __(':c cancelled (:a)', ['c' => $stats['cancelled'][0], 'a' => $tk($stats['cancelled'][1])]) }}
                · {{ __(':c returned (:a)', ['c' => $stats['returned'][0], 'a' => $tk($stats['returned'][1])]) }}
                · {{ __('Refunded :a', ['a' => $tk($stats['refunded'])]) }}
                @if ($stats['last']) · {{ __('last order :d', ['d' => $stats['last']->diffForHumans()]) }}@endif
            </p>

            @if ($channels->isNotEmpty())
                <p class="mt-4 text-xs font-medium text-gray-600">{{ __('Where the orders came from') }}</p>
                <div class="mt-1.5 flex h-2.5 overflow-hidden rounded-full bg-gray-100">
                    @foreach ($channels as $ch => $n)
                        <span style="width: {{ $n / $stats['orders'] * 100 }}%; background: {{ $palette[$ch] ?? '#9ca3af' }}"></span>
                    @endforeach
                </div>
                <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-600">
                    @foreach ($channels as $ch => $n)
                        <span class="inline-flex items-center gap-1"><i class="inline-block h-2 w-2 rounded-sm" style="background: {{ $palette[$ch] ?? '#9ca3af' }}"></i>{{ Order::channelLabel($ch) }} {{ $n }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">{{ __('Journey') }}</h3>
                <span class="text-xs text-gray-500">{{ __('Newest first') }}</span>
            </div>
            @if ($events->isEmpty())
                <p class="text-sm text-gray-500">{{ __('No orders or calls yet.') }}</p>
            @else
                <ol class="relative ml-1.5 border-l-2 border-gray-200">
                    @foreach ($events as $i => $e)
                        @php $r = $e['row']; @endphp
                        @if ($e['type'] === 'order')
                            @php [$badge, $dot] = $kind[$r->kind]; $s = $statuses[$r->status_id] ?? null; @endphp
                            <li class="relative mb-4 pl-5">
                                <span class="absolute -left-[7px] top-1.5 h-3 w-3 rounded-full ring-2 ring-white" style="background: {{ $dot }}"></span>
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-sm text-gray-900">
                                        <span class="font-semibold">{{ $r->created_at->format('d M Y') }}</span> ·
                                        @if (isset($openable[$r->id]))<a href="{{ route('orders.show', $r->id) }}" class="font-mono text-primary hover:underline">{{ $r->order_no }}</a>@else<span class="font-mono">{{ $r->order_no }}</span>@endif
                                    </p>
                                    <p class="shrink-0 text-base font-semibold tabular-nums {{ in_array($r->kind, ['returned', 'cancelled'], true) ? 'text-gray-400 line-through' : 'text-gray-900' }}">{{ $tk($r->grand_total) }}</p>
                                </div>
                                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-600">
                                    @if ($s)<x-badge :color="$s['color']">{{ __($s['name']) }}</x-badge>@endif
                                    <span class="rounded-full px-2 py-0.5 text-white" style="background: {{ $palette[$r->channel] ?? '#9ca3af' }}">{{ Order::channelLabel($r->channel) }}</span>
                                    @if ($r->kind === 'delivered' && abs($r->sold - (float) $r->grand_total) >= 1)<span>{{ __('received :a', ['a' => $tk($r->sold)]) }}</span>@endif
                                    @if ((float) $r->refunded > 0)<span class="text-red-700">{{ __('refunded :a', ['a' => $tk($r->refunded)]) }}</span>@endif
                                </p>
                                <p class="mt-1 text-sm text-gray-700">{{ $r->items->map(fn ($it) => $it->name_snapshot.' × '.\App\Support\Units::qty($it->qty, $it->unit))->join(', ') }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ __('By :n', ['n' => $r->moderator?->name ?? __('nobody')]) }}@if ($r->source_campaign) · {{ __('ad: :c', ['c' => $r->source_campaign]) }}@endif</p>
                            </li>
                        @elseif ($e['type'] === 'past')
                            @php [$dot, $badge, $label] = $pastKind[$r->status] ?? $pastKind['pending']; @endphp
                            <li class="relative mb-4 pl-5">
                                <span class="absolute -left-[7px] top-1.5 h-3 w-3 rounded-full ring-2 ring-white" style="background: {{ $dot }}"></span>
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-sm text-gray-900"><span class="font-semibold">{{ $r->at->format('d M Y') }}</span>@if ($r->external_no) · <span class="font-mono">#{{ $r->external_no }}</span>@endif @if ($r->cn) · <span class="font-mono">CN {{ $r->cn }}</span>@endif</p>
                                    <p class="shrink-0 text-base font-semibold tabular-nums {{ $r->status === 'delivered' ? 'text-gray-900' : 'text-gray-400 line-through' }}">{{ $tk($r->amount) }}</p>
                                </div>
                                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-600">
                                    <x-badge :color="$badge">{{ __($label) }}</x-badge>
                                    @if ($r->medium)<span class="rounded-full px-2 py-0.5 text-white" style="background: {{ $palette[$r->medium] ?? '#9ca3af' }}">{{ Order::channelLabel($r->medium) }}</span>@endif
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">{{ __('Imported: :s', ['s' => $r->source]) }}</span>
                                </p>
                                @if ($r->items)<p class="mt-1 text-sm text-gray-700">{{ $r->items }}</p>@endif
                            </li>
                        @else
                            <li class="relative mb-4 pl-5">
                                <span class="absolute -left-[7px] top-1.5 h-3 w-3 rounded-full bg-amber-500 ring-2 ring-white"></span>
                                <p class="text-sm text-gray-900"><span class="font-semibold">{{ $e['at']->format('d M Y') }}</span> · {{ $r->direction === 'in' ? __('Call from customer') : __('Call to customer') }}
                                    <x-badge color="amber">{{ __(CustomerCallController::OUTCOMES[$r->outcome] ?? $r->outcome) }}</x-badge></p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ __('By :n', ['n' => $r->by ?? '-']) }}
                                    @if ($r->duration_seconds) · {{ intdiv($r->duration_seconds, 60) }}m {{ $r->duration_seconds % 60 }}s @endif
                                    @if ($r->order_no) · {{ $r->order_no }}@endif
                                    @if ($r->recording_url) · <a href="{{ $r->recording_url }}" target="_blank" rel="noopener" class="text-primary hover:underline">{{ __('Recording') }}</a>@endif
                                </p>
                                @if ($r->note)<p class="mt-1 text-sm text-gray-700">{{ $r->note }}</p>@endif
                            </li>
                        @endif
                        {{-- Days to the next older order. --}}
                        @php $older = $events->slice($i + 1)->first(fn ($x) => $x['type'] !== 'call'); @endphp
                        @if ($e['type'] !== 'call' && $older)
                            <li class="-mt-2 mb-3 pl-5 text-xs text-gray-400">{{ trans_choice(':count day later|:count days later', $older['at']->diffInDays($e['at']), ['count' => (int) $older['at']->diffInDays($e['at'])]) }}</li>
                        @endif
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</x-layouts.app>
