@php
    use App\Support\Permissions\Mask;
    $tabUrl = fn ($t) => request()->fullUrlWithQuery(['tab' => $t, 'page' => null]);
    $n = fn ($k) => isset($counts[$k]) ? (int) $counts[$k]->n : 0;
    $statusChip = ['pending' => ['amber', __('Waiting')], 'approved' => ['blue', __('Approved')], 'paid' => ['green', __('Paid')], 'rejected' => ['red', __('Rejected')]];
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-green-800 focus:outline-none';
@endphp

<x-layouts.app :heading="__('Refunds')">
    <p class="mb-3 text-sm text-gray-500">{{ __('One person requests, another approves, then whoever sends the money marks it paid with the transaction ID. Paid refunds appear on the order as payments.') }}</p>

    <x-tabs :tabs="[
        'pending' => [__('Waiting'), $tabUrl('pending'), $n('pending')],
        'approved' => [__('To pay'), $tabUrl('approved'), $n('approved')],
        'paid' => [__('Paid'), $tabUrl('paid'), $n('paid')],
        'rejected' => [__('Rejected'), $tabUrl('rejected'), $n('rejected')],
        'all' => [__('All'), $tabUrl('all')],
    ]" :active="$tab" />

    <x-list.filter-bar :list="$list" :action="route('refunds.index')" :placeholder="__('Order no, phone or name')">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <x-simple-select name="method" :options="['' => __('Any method')] + $methods" :value="$list->filter('method') ?? ''" />
        <x-date-range :from="$list->filter('from')" :to="$list->filter('to')" />
    </x-list.filter-bar>

    @if ($refunds->isEmpty())
        <x-empty-state :message="$tab === 'pending' ? __('Nothing waiting for approval.') : __('No refunds here.')" />
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($refunds as $r)
                @php([$color, $label] = $statusChip[$r->status])
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p><a href="{{ route('orders.show', $r->order_id) }}" class="font-mono font-semibold hover:underline">{{ $r->order_no }}</a> <span class="text-sm text-gray-600">· {{ $r->ship_name }}</span>
                                @if ($r->complaint_id)<a href="{{ route('complaints.show', $r->complaint_id) }}" class="text-xs text-green-900 hover:underline">{{ __('complaint #:id', ['id' => $r->complaint_id]) }}</a>@endif</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-800">৳{{ number_format((float) $r->amount, 2) }} <span class="text-sm font-normal text-gray-500">{{ __('via') }} {{ $r->method }}@if ($r->recipient_number) → <span class="font-mono">{{ Mask::value($r->recipient_number, 'customer_contact') }}</span>@endif</span></p>
                            <p class="text-sm text-gray-600">{{ $r->reason ?? __('No reason given') }}{{ $r->note ? ' · '.$r->note : '' }}</p>
                            <p class="text-xs text-gray-400">{{ __('Requested by :n', ['n' => $r->requester ?? '-']) }} · {{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d M, g:i A') }}@if ($r->decider) · {{ $r->status === 'rejected' ? __('rejected by') : __('approved by') }} {{ $r->decider }}@endif{{ $r->decision_note ? ' · '.$r->decision_note : '' }}@if ($r->transaction_id) · <span class="font-mono">{{ $r->transaction_id }}</span>@endif</p>
                        </div>
                        <x-badge :color="$color">{{ $label }}</x-badge>
                    </div>

                    @if ($r->status === 'pending' && $canApprove)
                        @if ($r->requested_by === auth()->id() && ! auth()->user()->isOwner())
                            <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500">{{ __('You requested this one; someone else has to approve it.') }}</p>
                        @else
                            <form method="POST" action="{{ route('refunds.decide', $r->id) }}" class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                                @csrf
                                <input name="note" maxlength="255" placeholder="{{ __('Note (optional)') }}" class="{{ $input }} flex-1">
                                <x-button size="sm" name="decision" value="approve">{{ __('Approve') }}</x-button>
                                <x-button size="sm" variant="danger-outline" name="decision" value="reject">{{ __('Reject') }}</x-button>
                            </form>
                        @endif
                    @elseif ($r->status === 'approved' && $canPay)
                        <form method="POST" action="{{ route('refunds.paid', $r->id) }}" class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                            @csrf
                            <input name="transaction_id" maxlength="100" @if ($r->requires_trx_id) required @endif placeholder="{{ __('Transaction ID') }}" class="{{ $input }} flex-1 font-mono">
                            <x-button size="sm">{{ __('Money sent') }}</x-button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $refunds->links() }}</div>
    @endif
</x-layouts.app>
