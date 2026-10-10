@php
    use App\Support\Permissions\Mask;
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    $stages = ['none' => __('Nobody'), 'sales' => __('Sales / moderator'), 'verification' => __('Verification'), 'packing' => __('Packing'), 'dispatch' => __('Dispatch'), 'courier' => __('Courier'), 'customer' => __('Customer')];
    $resolutions = ['solved' => __('Solved'), 'replacement' => __('Replacement sent'), 'refunded' => __('Refunded'), 'rejected' => __('Not valid')];
    $refundStatus = ['pending' => ['amber', __('Waiting for approval')], 'approved' => ['blue', __('Approved, not paid yet')], 'paid' => ['green', __('Paid')], 'rejected' => ['red', __('Rejected')]];
    $due = $complaint->sla_due_at;
@endphp

<x-layouts.app :heading="__('Complaint #:id', ['id' => $complaint->id])">
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-card>
                <x-slot:actions>
                    @if ($complaint->status === 'resolved')
                        <x-badge color="green">{{ $resolutions[$complaint->resolution] ?? __('Resolved') }}</x-badge>
                    @elseif ($complaint->isOverdue())
                        <x-badge color="red">{{ __('Overdue :t', ['t' => $due->diffForHumans(null, true)]) }}</x-badge>
                    @else
                        <x-badge color="amber">{{ __('Due in :t', ['t' => $due?->diffForHumans(null, true) ?? '-']) }}</x-badge>
                    @endif
                </x-slot:actions>
                <h2 class="text-base font-semibold text-gray-800">{{ $complaint->category->label_en }} <span class="text-sm font-normal text-gray-400">· {{ __('via') }} {{ $complaint->source }}</span></h2>
                <p class="mt-2 whitespace-pre-line text-sm text-gray-800">{{ $complaint->description }}</p>
                <dl class="mt-4 grid gap-2 text-sm md:grid-cols-2">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Customer') }}</dt><dd>{{ $complaint->customer_name }} · <span class="font-mono">{{ Mask::value($complaint->customer_phone, 'customer_contact') }}</span></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Order') }}</dt><dd>@if ($order)<a href="{{ route('orders.show', $order->id) }}" class="font-mono font-medium text-green-900 hover:underline">{{ $order->order_no }}</a> · <x-order-status :order="$order" :statuses="$statuses" /> · ৳{{ number_format((float) $order->grand_total) }}@else -@endif</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Stage at fault') }}</dt><dd>{{ $stages[$complaint->blame_stage] }}@if ($blamedName) ({{ $blamedName }})@endif</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Opened') }}</dt><dd>{{ $complaint->created_at->format('d M Y, g:i A') }}</dd></div>
                    @if ($complaint->status === 'resolved')
                        <div class="md:col-span-2"><dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('Resolution') }}</dt><dd>{{ $resolutions[$complaint->resolution] ?? $complaint->resolution }}{{ $complaint->resolution_note ? ' · '.$complaint->resolution_note : '' }} <span class="text-gray-400">· {{ $complaint->resolved_at?->format('d M, g:i A') }}</span></dd></div>
                    @endif
                </dl>
            </x-card>

            <x-card :title="__('Photos')">
                @if ($photos->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('No photo yet.') }}</p>
                @else
                    <div class="grid grid-cols-3 gap-2 md:grid-cols-4">
                        @foreach ($photos as $p)
                            <a href="{{ route('complaints.photo', [$complaint, $p->id]) }}" target="_blank" class="block overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                <img src="{{ route('complaints.photo', [$complaint, $p->id]) }}" alt="{{ $p->original_name }}" loading="lazy" class="aspect-square w-full object-cover">
                            </a>
                        @endforeach
                    </div>
                @endif
                <form method="POST" action="{{ route('complaints.photos', $complaint) }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="file" name="photos[]" multiple accept="image/*" required class="text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-green-50 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-green-900">
                    <x-button size="sm" variant="secondary">{{ __('Add photos') }}</x-button>
                    @error('photos.*')<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror
                </form>
            </x-card>

            @if ($order)
                <x-card :title="__('Refunds on this complaint')">
                    @forelse ($refunds as $r)
                        @php([$color, $label] = $refundStatus[$r->status])
                        <div class="flex items-center justify-between gap-2 border-b border-gray-100 py-2 text-sm last:border-0">
                            <span>৳{{ number_format((float) $r->amount, 2) }} · {{ $r->method }}@if ($r->transaction_id) · <span class="font-mono text-xs">{{ $r->transaction_id }}</span>@endif</span>
                            <x-badge :color="$color">{{ $label }}</x-badge>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No refund requested.') }}</p>
                    @endforelse
                    @if ($canRefund && $complaint->status === 'open')
                        <details class="mt-3 rounded-lg border border-gray-200 p-3" @if ($errors->hasAny(['amount', 'method_id', 'recipient_number'])) open @endif>
                            <summary class="cursor-pointer text-sm font-medium text-green-900">{{ __('Request a refund') }} <span class="font-normal text-gray-500">({{ __('up to ৳:m', ['m' => number_format($maxRefund, 2)]) }})</span></summary>
                            <form method="POST" action="{{ route('refunds.store') }}" class="mt-3 grid gap-2 md:grid-cols-2">
                                @csrf
                                <input type="hidden" name="order_id" value="{{ $order->id }}">
                                <input type="hidden" name="complaint_id" value="{{ $complaint->id }}">
                                <div><input name="amount" type="number" step="0.01" min="1" max="{{ max(1, $maxRefund) }}" required value="{{ old('amount') }}" placeholder="{{ __('Amount (৳)') }}" class="{{ $input }}">@error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                                <x-simple-select name="method_id" :options="$methods" :value="old('method_id', array_key_first($methods))" full-width class="w-full" />
                                <div><input name="recipient_number" value="{{ old('recipient_number', $complaint->customer_phone) }}" maxlength="20" inputmode="tel" placeholder="{{ __('Send to number (bKash / Nagad)') }}" class="{{ $input }} font-mono">@error('recipient_number')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                                <x-simple-select name="reason_id" :options="['' => __('Reason…')] + $refundReasons" :value="old('reason_id', '')" full-width class="w-full" />
                                <input name="note" maxlength="255" placeholder="{{ __('Note for the approver') }}" class="{{ $input }} md:col-span-2">
                                <div class="md:col-span-2"><x-button size="sm">{{ __('Send for approval') }}</x-button></div>
                            </form>
                        </details>
                    @elseif ($canRefund && $maxRefund <= 0 && $refunds->isEmpty())
                        <p class="mt-2 text-xs text-gray-500">{{ __('Nothing to refund: the customer has not paid on this order.') }}</p>
                    @endif
                </x-card>
            @endif

            <x-card :title="__('Timeline')">
                <form method="POST" action="{{ route('complaints.notes', $complaint) }}" class="mb-4 flex gap-2">
                    @csrf
                    <input name="body" required maxlength="1000" placeholder="{{ __('Add a note: what the customer said, what was agreed…') }}" class="{{ $input }}">
                    <x-button size="sm" variant="secondary" class="shrink-0">{{ __('Add') }}</x-button>
                </form>
                <x-timeline :entries="$events" with-year />
            </x-card>
        </div>

        <div class="space-y-6">
            @if ($canEdit && $complaint->status === 'open')
                <x-card :title="__('Resolve')">
                    <form method="POST" action="{{ route('complaints.resolve', $complaint) }}" class="space-y-3" x-data="{ r: @js(old('resolution', 'solved')) }">
                        @csrf
                        <input type="hidden" name="resolution" :value="r">
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($resolutions as $key => $label)
                                <button type="button" @click="r = @js($key)" class="rounded-full border px-2.5 py-1 text-xs" :class="r === @js($key) ? 'border-green-900 bg-green-900 text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                            @endforeach
                        </div>
                        @error('resolution')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        <div>
                            <p class="mb-1 text-xs text-gray-500">{{ __('Whose stage caused it? Only that stage is counted in quality.') }}</p>
                            <x-simple-select name="blame_stage" :options="$stages" :value="old('blame_stage', $complaint->blame_stage)" full-width class="w-full" />
                        </div>
                        @if ($staff)
                            <div>
                                <p class="mb-1 text-xs text-gray-500">{{ __('Person (empty = whoever worked that stage on the order)') }}</p>
                                <x-simple-select name="blamed_user_id" :options="['' => __('Automatic')] + $staff" :value="old('blamed_user_id', '')" full-width class="w-full" />
                            </div>
                        @endif
                        <input name="note" maxlength="500" value="{{ old('note') }}" placeholder="{{ __('What was done for the customer') }}" class="{{ $input }}">
                        <x-button class="w-full">{{ __('Mark resolved') }}</x-button>
                    </form>
                </x-card>
            @elseif ($canEdit)
                <x-card :title="__('Reopen')">
                    <form method="POST" action="{{ route('complaints.reopen', $complaint) }}" class="space-y-2">
                        @csrf
                        <input name="why" maxlength="500" placeholder="{{ __('Why it is back') }}" class="{{ $input }}">
                        <x-button variant="secondary" class="w-full">{{ __('Reopen complaint') }}</x-button>
                    </form>
                </x-card>
            @endif

            <x-card :title="__('Assigned to')">
                <p class="text-sm text-gray-800">{{ $complaint->assignee?->name ?? __('Nobody') }}</p>
                @if ($canEdit && $staff && $complaint->status === 'open')
                    <form method="POST" action="{{ route('complaints.assign', $complaint) }}" class="mt-3 flex gap-2">
                        @csrf
                        <x-simple-select name="assigned_to" :options="$staff" :value="$complaint->assigned_to" full-width class="w-full" />
                        <x-button size="sm" variant="secondary" class="shrink-0">{{ __('Give') }}</x-button>
                    </form>
                @endif
            </x-card>

            @if ($order)
                <x-card :title="__('Order money')">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Total') }}</dt><dd class="tabular-nums">৳{{ number_format((float) $order->grand_total, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Advance (verified)') }}</dt><dd class="tabular-nums">৳{{ number_format((float) $order->advance_verified, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('COD') }}</dt><dd class="tabular-nums">৳{{ number_format((float) $order->cod_amount, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Payment status') }}</dt><dd>{{ str_replace('_', ' ', $order->payment_status) }}</dd></div>
                    </dl>
                </x-card>
            @endif
        </div>
    </div>
</x-layouts.app>
