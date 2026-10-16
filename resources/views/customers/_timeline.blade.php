{{-- A customer's journey: IQS orders, imported orders and calls, newest first, with the days between orders. --}}
@php
    use App\Http\Controllers\CustomerCallController;
    use App\Models\Order;
    $tk = fn ($n) => '৳'.number_format((float) $n);
    $palette = \App\Models\Order::CHANNEL_COLORS;
    $pastKind = ['delivered' => ['#16a34a', 'green', 'Delivered'], 'cancelled' => ['#dc2626', 'red', 'Cancelled'], 'returned' => ['#dc2626', 'red', 'Returned'],
        'refunded' => ['#dc2626', 'amber', 'Refunded'], 'pending' => ['#9ca3af', 'gray', 'Not finished']];
    $kind = ['delivered' => ['green', '#16a34a'], 'returned' => ['red', '#dc2626'], 'cancelled' => ['red', '#dc2626'], 'open' => ['blue', '#2563eb']];
@endphp
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
                        @if (isset($openable[$r->id]))<a href="{{ route('orders.show', $r->id) }}" @click.prevent="window.dispatchEvent(new CustomEvent('order-new', { detail: { url: @js(route('orders.show', ['order' => $r->id, 'embed' => 1])), title: @js($r->order_no), page: @js(route('orders.show', $r->id)) } }))" class="font-mono text-primary hover:underline">{{ $r->order_no }}</a>@else<span class="font-mono">{{ $r->order_no }}</span>@endif
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
                    <x-badge color="amber">{{ __(CustomerCallController::OUTCOMES[$r->outcome] ?? $r->outcome) }}</x-badge>
                    @if ($r->satisfaction !== null)<x-badge :color="$r->satisfaction < 50 ? 'red' : ($r->satisfaction < 75 ? 'amber' : 'green')">{{ __(':s% satisfied', ['s' => $r->satisfaction]) }}</x-badge>@endif</p>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ __('By :n', ['n' => $r->by ?? '-']) }}
                    @if ($r->duration_seconds) · {{ intdiv($r->duration_seconds, 60) }}m {{ $r->duration_seconds % 60 }}s @endif
                    @if ($r->order_no) · {{ $r->order_no }}@endif
                    @if ($r->recording_url) · <a href="{{ $r->recording_url }}" target="_blank" rel="noopener" class="text-primary hover:underline">{{ __('Recording') }}</a>@endif
                    @if ($r->proof_path && (auth()->id() === $r->user_id || auth()->user()->can('calls.manage'))) · <x-play-call :call="$r" :title="$customer->name ?? ''" :sub="($r->by ?? '').' · '.\Illuminate\Support\Carbon::parse($r->started_at)->format('d M, g:i A')" />@if ($r->proof_old) <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">{{ __('Earlier file') }}</span>@endif @endif
                </p>
                @if ($r->note)<p class="mt-1 text-sm text-gray-700">{{ $r->note }}</p>@endif
            </li>
        @endif
        {{-- Days to the next older order. --}}
        @php $older = $events->slice($i + 1)->first(fn ($x) => $x['type'] !== 'call'); @endphp
        @if ($e['type'] !== 'call' && $older)
            @php($days = (int) $older['at']->diffInDays($e['at']))
            <li class="-mt-2 mb-3 pl-5 text-xs text-gray-400">{{ trans_choice(':count day later|:count days later', $days, ['count' => $days]) }}</li>
        @endif
    @endforeach
</ol>
@endif
