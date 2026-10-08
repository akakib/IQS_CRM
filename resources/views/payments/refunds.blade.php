@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $tabs = [
        'owed' => [__('Owed'), request()->fullUrlWithQuery(['tab' => 'owed', 'page' => null]), (int) $owedCount->n],
        'done' => [__('Done'), request()->fullUrlWithQuery(['tab' => 'done', 'page' => null])],
    ];
@endphp

<x-layouts.app :heading="__('Refunds')">
    @include('payments._tabs', ['active' => 'refunds'])

    <p class="mb-4 text-sm text-gray-500">{{ __('Orders cancelled or returned after the customer paid. Give the money back (how, TrxID), or keep it as credit: it is used by itself on their next order.') }}</p>

    <x-tabs :tabs="$tabs" :active="$tab" />

    <x-list.filter-bar :list="$list" :action="route('refunds.index')" :placeholder="__('Order no, phone or TrxID')">
        <input type="hidden" name="tab" value="{{ $tab }}">
    </x-list.filter-bar>

    @if ($tab === 'owed' && $owedCount->n)
        <p class="mb-3 text-sm text-gray-600">{{ trans_choice(':count order|:count orders', $owedCount->n, ['count' => $owedCount->n]) }} · {{ __('owed') }} <b class="tabular-nums">৳{{ number_format((float) $owedCount->total) }}</b></p>
    @endif

    @if ($rows->isEmpty())
        <x-empty-state :message="$tab === 'owed' ? __('Nothing is owed back.') : __('No refunds yet.')" />
    @elseif ($tab === 'owed')
        <div class="space-y-2">
            @foreach ($rows as $o)
                <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900"><a href="{{ route('orders.show', $o->id) }}" class="font-mono hover:underline">{{ $o->order_no }}</a> · {{ $o->ship_name }}</p>
                        <p class="text-xs text-gray-500"><span class="font-mono">{{ $o->ship_phone }}</span> · {{ __($statuses[$o->status_id]['name'] ?? '') }} · {{ __('paid ৳:a', ['a' => number_format((float) $o->advance_verified)]) }}</p>
                    </div>
                    <p class="text-lg font-semibold tabular-nums text-red-700">৳{{ number_format((float) $o->refund_due) }}</p>
                    <div class="flex gap-2">
                        <x-button type="button" size="sm" @click="$dispatch('open-modal', 'refund-{{ $o->id }}')">{{ __('Give back') }}</x-button>
                        @if ($o->customer_id)
                            <form method="POST" action="{{ route('refunds.credit', $o->id) }}" id="credit-{{ $o->id }}">@csrf<input type="hidden" name="amount" value="{{ $o->refund_due }}">
                                <x-button type="button" size="sm" variant="secondary" @click="$dispatch('open-confirm', { id: 'credit-confirm', form: 'credit-{{ $o->id }}', label: @js($o->order_no.' · ৳'.number_format((float) $o->refund_due)) })">{{ __('Keep as credit') }}</x-button>
                            </form>
                        @endif
                    </div>
                </div>

                <x-modal :id="'refund-'.$o->id" :title="__('Give back on :no', ['no' => $o->order_no])" persistent>
                    <form method="POST" action="{{ route('refunds.refund', $o->id) }}" id="refund-form-{{ $o->id }}" class="space-y-3">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700">{{ __('Amount') }}
                            <input name="amount" type="number" step="0.01" min="1" max="{{ $o->refund_due }}" value="{{ $o->refund_due }}" required class="{{ $input }} mt-1 tabular-nums">
                        </label>
                        <div>
                            <p class="mb-1 text-sm font-medium text-gray-700">{{ __('How it was sent') }}</p>
                            <x-simple-select name="method_id" :options="$methods" :value="null" :placeholder="__('Choose how')" full-width class="w-full" />
                        </div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Transaction ID') }}
                            <input name="transaction_id" maxlength="100" class="{{ $input }} mt-1 font-mono">
                        </label>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Note (optional)') }}
                            <input name="note" maxlength="255" class="{{ $input }} mt-1">
                        </label>
                    </form>
                    <x-slot:footer>
                        <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'refund-{{ $o->id }}')">{{ __('Cancel') }}</x-button>
                        <x-button type="submit" form="refund-form-{{ $o->id }}">{{ __('Save') }}</x-button>
                    </x-slot:footer>
                </x-modal>
            @endforeach
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    @else
        <div class="space-y-2">
            @foreach ($rows as $r)
                <div class="flex flex-col gap-1 rounded-xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900"><a href="{{ route('orders.show', $r->order_id) }}" class="font-mono hover:underline">{{ $r->order_no }}</a> · {{ $r->ship_name }}</p>
                        <p class="text-xs text-gray-500">{{ $r->system_key === 'credit' ? __('Kept as credit') : __('Given back by :m', ['m' => $r->method]) }}@if ($r->transaction_id) · <span class="font-mono">{{ $r->transaction_id }}</span>@endif · {{ $r->person ?? '-' }} · {{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d M, g:i A') }}</p>
                    </div>
                    <p class="font-semibold tabular-nums text-gray-900">৳{{ number_format((float) $r->amount) }}</p>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif

    <x-confirm-modal id="credit-confirm" :verb="__('Keep as credit')" :message="__('It is used by itself on their next order.')" :danger="false" />
</x-layouts.app>
