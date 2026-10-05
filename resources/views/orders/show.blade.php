@php
    use App\Support\Permissions\Mask;
    $user = auth()->user();
    $s = $statuses[$order->status_id];
    $canAct = $order->moderator_id === $user->id || $user->permissionScope('orders.view') === 'all';
    $noteIcon = ['status' => '●', 'call' => '☎', 'chat' => '✉', 'payment' => '৳', 'assignment' => '👤', 'amendment' => '✎', 'courier' => '🚚', 'verification' => '✓', 'rider' => '🛵'];
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
@endphp

<x-layouts.app :heading="$order->order_no">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('orders.index') }}" class="text-sm text-primary hover:underline">{{ __('Orders') }}</a>
            <span class="text-gray-300">/</span>
            <x-order-status :order="$order" :statuses="$statuses" />
            <span class="text-xs text-gray-500">{{ $order->channel }} · {{ $order->created_at->format('d M Y, g:i A') }}</span>
            @if ($order->holdReason)<x-badge color="amber">{{ $order->holdReason->label_en }}{{ $order->hold_expected_date ? ' · '.$order->hold_expected_date->format('d M') : '' }}</x-badge>@endif
        </div>
        <div class="flex items-center gap-2">
            @if ($canEdit)
                <x-button variant="secondary" :href="route('orders.edit', $order)">{{ __('Edit order') }}</x-button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @foreach ($amendments as $a)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-medium text-amber-900">{{ __('Change waiting for approval') }} · {{ $a->reason }}</p>
                    <ul class="mt-1 list-inside list-disc text-sm text-amber-900">
                        @foreach (json_decode($a->changes, true) as $c)
                            <li>{{ match ($c['type']) {
                                'added' => __('Add :i ×:q', ['i' => $c['item'], 'q' => $c['to'] + 0]),
                                'removed' => __('Remove :i', ['i' => $c['item']]),
                                'qty' => __(':i ×:a → ×:b', ['i' => $c['item'], 'a' => $c['from'] + 0, 'b' => $c['to'] + 0]),
                                'price' => __(':i ৳:a → ৳:b', ['i' => $c['item'], 'a' => $c['from'], 'b' => $c['to']]),
                                default => __(':f: :a → :b', ['f' => str_replace(['ship_', '_'], ['', ' '], $c['item']), 'a' => $c['from'], 'b' => $c['to']]),
                            } }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-amber-800">{{ __('Total changes by ৳:d · asked by :n', ['d' => $a->amount_diff, 'n' => $a->by]) }}</p>
                    @can('orders.approve')
                        <div class="mt-3 flex gap-2">
                            <form method="POST" action="{{ route('orders.amendments.decide', [$order, $a->id]) }}">@csrf<input type="hidden" name="decision" value="approve"><x-button size="sm">{{ __('Approve') }}</x-button></form>
                            <form method="POST" action="{{ route('orders.amendments.decide', [$order, $a->id]) }}">@csrf<input type="hidden" name="decision" value="reject"><x-button size="sm" variant="secondary">{{ __('Reject') }}</x-button></form>
                        </div>
                    @endcan
                </div>
            @endforeach

            {{-- Actions: only the transitions this person may do from the current status. --}}
            @if ($canAct && $targets)
                <x-card :title="__('Next step')" x-data="{ to: null, needsReason: false, reasonType: 'status', reason: '' }">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($targets as $t)
                            <button type="button" @click="to = @js($t['key']); needsReason = @js($t['requires_reason']); reasonType = @js($t['reason_type']); reason = ''"
                                class="rounded-lg border px-3 py-2 text-sm font-medium" style="border-color: {{ $t['color'] }}; color: {{ $t['color'] }}"
                                :style="to === @js($t['key']) ? 'background: {{ $t['color'] }}; color: #fff' : ''">{{ __($t['name']) }}</button>
                        @endforeach
                    </div>
                    <form x-show="to" x-cloak method="POST" action="{{ route('orders.transition', $order) }}" class="mt-4 space-y-2" @select-change="reason = $event.detail ?? ''">
                        @csrf
                        <input type="hidden" name="to" :value="to">
                        <input type="hidden" name="lock_version" value="{{ $order->lock_version }}">
                        {{-- One reason field; each dropdown (cancel / hold / return / other) only sets it. --}}
                        <input type="hidden" name="reason_id" :value="reason">
                        @foreach (['cancel', 'hold', 'return', 'status'] as $type)
                            <div x-show="reasonType === @js($type) && (needsReason || @js($type) !== 'status')">
                                <x-simple-select :options="['' => __('Choose a reason')] + $reasons[$type]" value="" full-width class="w-full" />
                            </div>
                        @endforeach
                        <div x-show="to === 'hold'"><x-date-input name="hold_expected_date" :min="now()->toDateString()" :placeholder="__('Expected date')" full-width /></div>
                        <input name="note" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="{{ $input }}">
                        @error('reason_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @error('status')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @error('order')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        <x-button>{{ __('Save') }}</x-button>
                    </form>
                </x-card>
            @endif

            <x-card :title="__('Items')">
                <div class="divide-y divide-gray-100">
                    @foreach ($order->items as $item)
                        <div class="flex items-center justify-between gap-3 py-2 text-sm">
                            <div class="min-w-0"><p class="text-gray-800">{{ $item->name_snapshot }}</p><p class="font-mono text-xs text-gray-400">{{ $item->sku_snapshot }}</p></div>
                            <p class="shrink-0 text-gray-500">{{ rtrim(rtrim($item->qty, '0'), '.') }}{{ $item->unit === 'g' ? ' g' : ' ×' }} ৳{{ number_format((float) $item->unit_price, 2) }}</p>
                            <p class="w-24 shrink-0 text-right tabular-nums">৳{{ number_format((float) $item->line_total, 2) }}</p>
                        </div>
                    @endforeach
                </div>
                <dl class="mt-3 space-y-1 border-t border-gray-100 pt-3 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('Subtotal') }}</dt><dd class="tabular-nums">৳{{ number_format((float) $order->subtotal, 2) }}</dd></div>
                    @if ((float) $order->discount_total)<div class="flex justify-between"><dt class="text-gray-500">{{ __('Discount') }}</dt><dd class="tabular-nums">-৳{{ number_format((float) $order->discount_total, 2) }}</dd></div>@endif
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('Delivery') }} ({{ $order->zone?->name ?? '-' }}, {{ number_format($order->total_weight_g / 1000, 2) }} kg)</dt><dd class="tabular-nums">৳{{ number_format((float) $order->delivery_charge, 2) }}</dd></div>
                    <div class="flex justify-between font-semibold"><dt>{{ __('Total') }}</dt><dd class="tabular-nums">৳{{ number_format((float) $order->grand_total, 2) }}</dd></div>
                    @if ((float) $order->advance_verified)<div class="flex justify-between"><dt class="text-gray-500">{{ __('Advance (verified)') }}</dt><dd class="tabular-nums">-৳{{ number_format((float) $order->advance_verified, 2) }}</dd></div>@endif
                    <div class="flex justify-between text-base font-semibold text-primary"><dt>{{ __('COD to collect') }}</dt><dd class="tabular-nums">৳{{ number_format((float) $order->cod_amount, 2) }}</dd></div>
                </dl>
            </x-card>

            @if ($payments->isNotEmpty())
                <x-card :title="__('Payments')">
                    @foreach ($payments as $p)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-50 py-2 text-sm last:border-0">
                            <span>{{ ucfirst($p->payment_type) }} · {{ $p->method }} · <span class="font-mono">{{ $p->transaction_id ?? '-' }}</span> · <b>৳{{ number_format((float) $p->amount, 2) }}</b></span>
                            @if ($p->status === 'pending_verification')
                                @can('orders.approve')
                                    <span class="flex gap-2">
                                        <form method="POST" action="{{ route('orders.payments.verify', [$order, $p->id]) }}">@csrf<input type="hidden" name="decision" value="approve"><x-button size="sm">{{ __('Verify') }}</x-button></form>
                                        <form method="POST" action="{{ route('orders.payments.verify', [$order, $p->id]) }}">@csrf<input type="hidden" name="decision" value="reject"><x-button size="sm" variant="secondary">{{ __('Reject') }}</x-button></form>
                                    </span>
                                @else
                                    <x-badge color="amber">{{ __('Waiting for verification') }}</x-badge>
                                @endcan
                            @else
                                <x-badge :color="$p->status === 'verified' ? 'green' : 'red'">{{ ucfirst($p->status) }}</x-badge>
                            @endif
                        </div>
                    @endforeach
                </x-card>
            @endif

            {{-- One timeline: status changes, edits, calls, payments, all with person and time. --}}
            <x-card :title="__('Timeline')">
                <form method="POST" action="{{ route('orders.notes', $order) }}" class="mb-4 flex flex-col gap-2 md:flex-row" x-data="{ type: 'call' }">
                    @csrf
                    <input type="hidden" name="type" :value="type">
                    <div class="flex shrink-0 gap-1">
                        @foreach (['call' => __('Call'), 'chat' => __('Chat'), 'manual' => __('Note')] as $key => $label)
                            <button type="button" @click="type = @js($key)" class="rounded-lg border px-2.5 py-2 text-xs" :class="type === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                        @endforeach
                    </div>
                    <input name="body" required maxlength="2000" placeholder="{{ __('What happened? e.g. called, customer will confirm tonight') }}" class="{{ $input }}">
                    <x-button class="shrink-0">{{ __('Add') }}</x-button>
                </form>
                <x-timeline :entries="$notes" with-year />
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card :title="__('Customer')">
                <p class="font-medium text-gray-800"><a href="{{ route('customers.show', $order->customer_id) }}" class="hover:underline">{{ $order->ship_name }}</a></p>
                <p class="font-mono text-sm text-gray-600">{{ Mask::value($order->ship_phone, 'customer_contact') }}</p>
                @if ($order->ship_alt_phone)<p class="font-mono text-sm text-gray-600">{{ Mask::value($order->ship_alt_phone, 'customer_contact') }}</p>@endif
                <p class="mt-2 text-sm text-gray-600">
                    @canseefield('customer_contact'){{ collect([$order->ship_address, $order->ship_thana, $order->ship_district])->filter()->join(', ') }}@else<span class="text-gray-400">{{ __('Address hidden for your role') }}</span>@endcanseefield
                </p>
                <p class="mt-3 text-xs text-gray-500">{{ __(':o orders · :d delivered · :r returned', ['o' => $order->customer->orders_count, 'd' => $order->customer->delivered_count, 'r' => $order->customer->returned_count]) }}
                    @if ($order->customer->risk_level !== 'normal')<x-badge color="red">{{ ucfirst($order->customer->risk_level) }}</x-badge>@endif</p>
                @if ($order->customer_note)<p class="mt-3 rounded-lg bg-amber-50 p-2 text-sm text-amber-900">{{ $order->customer_note }}</p>@endif
            </x-card>

            <x-card :title="__('Automatic checks')">
                @if ($verification)
                    @php($in = json_decode($verification->inputs_snapshot, true))
                    <p class="text-sm text-gray-800">{{ ucfirst(str_replace('_', ' ', $verification->outcome)) }}</p>
                    <p class="text-xs text-gray-500">{{ $verification->rule ?? __('no rule matched') }} · {{ \Illuminate\Support\Carbon::parse($verification->created_at)->diffForHumans() }}</p>
                    <dl class="mt-2 space-y-0.5 text-xs">
                        @foreach (($in['providers'] ?? []) as $p)
                            <div class="flex justify-between"><dt class="text-gray-500">{{ ucfirst($p['key']) }}</dt><dd>{{ $p['success_rate'] === null ? __('no history') : $p['success_rate'].'%' }} · {{ $p['total_parcels'] }} {{ __('parcels') }}</dd></div>
                        @endforeach
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('New customer') }}</dt><dd>{{ ($in['is_new_customer'] ?? false) ? __('yes') : __('no') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Advance') }}</dt><dd>{{ $in['advance_paid_percent'] ?? 0 }}%</dd></div>
                    </dl>
                @else
                    <p class="text-sm text-gray-400">{{ __('Not run yet.') }}</p>
                @endif
                @can('orders.approve')
                    <form method="POST" action="{{ route('orders.verify', $order) }}" class="mt-3">@csrf<x-button size="sm" variant="secondary">{{ __('Run checks again') }}</x-button></form>
                @endcan
            </x-card>

            <x-card :title="__('Assigned to')">
                <p class="text-sm text-gray-800">{{ $order->moderator?->name ?? __('Not taken yet') }}</p>
                @if ($staffOptions)
                    <form method="POST" action="{{ route('orders.reassign', $order) }}" class="mt-3 space-y-2">
                        @csrf
                        <x-simple-select name="user_id" :options="['' => __('Give to…')] + $staffOptions" value="" full-width class="w-full" />
                        <x-simple-select name="reason_id" :options="['' => __('Reason')] + $reasons['reassign']" value="" full-width class="w-full" />
                        <x-button size="sm" variant="secondary">{{ __('Reassign') }}</x-button>
                    </form>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
