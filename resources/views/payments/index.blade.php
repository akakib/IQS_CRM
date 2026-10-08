@php
    $statusOptions = ['check' => __('To check'), 'verified' => __('Verified'), 'rejected' => __('Rejected'), 'all' => __('All')];
    $badge = fn ($p) => match ($p->status) {
        'verified' => ['green', __('Verified')],
        'rejected' => ['red', __('Rejected')],
        default => $p->counts_now ? ['amber', __('To check · COD already lowered')] : ['amber', __('To check · order waits')],
    };
@endphp

<x-layouts.app :heading="__('Payments to check')">
    @include('payments._tabs', ['active' => 'check'])

    <p class="mb-4 text-sm text-gray-500">{{ __('Compare each TrxID and amount with the bKash / Nagad statement, tick the ones that match and verify them together. A small advance (up to ৳:l) already lowered the COD; a bigger one holds its order until it is checked.', ['l' => number_format((int) settings('payments.trust_up_to'))]) }}</p>

    <x-list.filter-bar :list="$list" :action="route('payments.index')" :placeholder="__('TrxID, order no or phone')">
        <x-simple-select name="status" :options="$statusOptions" :value="$status" />
        <x-simple-select name="method" :options="['' => __('Any method')] + $methods" :value="$list->filter('method') ?? ''" />
        <x-date-range :from="$list->filter('from')" :to="$list->filter('to')" />
    </x-list.filter-bar>

    <p class="mb-3 text-sm text-gray-600">{{ trans_choice(':count payment|:count payments', $summary->n, ['count' => $summary->n]) }} · <b class="tabular-nums">৳{{ number_format((float) $summary->total, 2) }}</b></p>

    @if ($payments->isEmpty())
        <x-empty-state :message="$status === 'check' ? __('Nothing to check. All payments are done.') : __('No payments here.')" />
    @else
        <form method="POST" action="{{ route('payments.decide') }}" id="pay-decide" x-data="{ picked: [] }">
            @csrf
            {{-- "Reject ticked" submits through the confirm dialog with this value; "Verify ticked" sends approve, which comes later and wins. --}}
            <input type="hidden" name="decision" value="reject">
            @if ($status === 'check')
                <div class="sticky top-0 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" class="rounded border-gray-300 text-primary" @change="picked = $event.target.checked ? [...document.querySelectorAll('[data-pay]')].map(e => e.value) : []"> {{ __('All on this page') }}
                    </label>
                    <span class="text-sm text-gray-500" x-text="picked.length + ' ' + @js(__('ticked'))"></span>
                    <span class="flex-1"></span>
                    <x-button type="submit" name="decision" value="approve" x-bind:disabled="!picked.length">{{ __('Verify ticked') }}</x-button>
                    <x-button type="button" variant="danger-outline" x-bind:disabled="!picked.length"
                        @click="$dispatch('open-confirm', { id: 'pay-reject', form: 'pay-decide' })">{{ __('Reject ticked') }}</x-button>
                </div>
            @endif

            <x-list.table>
                <x-slot:head>
                    @if ($status === 'check')<th class="w-8"></th>@endif
                    <th><x-list.sort :list="$list" column="received_at">{{ __('Received') }}</x-list.sort></th>
                    <th>{{ __('Order') }}</th>
                    <th>{{ __('Method') }}</th>
                    <th>{{ __('TrxID') }}</th>
                    <th>{{ __('Sender') }}</th>
                    <th class="text-right"><x-list.sort :list="$list" column="amount">{{ __('Amount') }}</x-list.sort></th>
                    <th>{{ __('Status') }}</th>
                </x-slot:head>
                @foreach ($payments as $p)
                    @php [$color, $label] = $badge($p); @endphp
                    <tr>
                        @if ($status === 'check')<td><input type="checkbox" name="ids[]" value="{{ $p->id }}" data-pay x-model="picked" class="rounded border-gray-300 text-primary"></td>@endif
                        <td class="text-gray-600">{{ \Illuminate\Support\Carbon::parse($p->received_at)->format('d M, g:i A') }}<span class="block text-xs text-gray-400">{{ $p->added_by ?? '-' }}</span></td>
                        <td><a href="{{ route('orders.show', $p->order_id) }}" class="font-mono font-medium text-primary hover:underline">{{ $p->order_no }}</a><span class="block text-xs text-gray-500">{{ $p->ship_name }}</span></td>
                        <td class="text-gray-700">{{ $p->method }}</td>
                        <td class="font-mono text-gray-900">{{ $p->transaction_id ?? '-' }}</td>
                        <td class="font-mono text-gray-600">{{ $p->sender_number ?? '-' }}</td>
                        <td class="text-right font-semibold tabular-nums text-gray-900">৳{{ number_format((float) $p->amount, 2) }}</td>
                        <td><x-badge :color="$color">{{ $label }}</x-badge>@if ($p->checked_by)<span class="block text-xs text-gray-400">{{ $p->checked_by }}</span>@endif</td>
                    </tr>
                @endforeach
            </x-list.table>

            <x-list.cards>
                @foreach ($payments as $p)
                    @php [$color, $label] = $badge($p); @endphp
                    <label class="block">
                        <x-record-card :title="$p->order_no.' · ৳'.number_format((float) $p->amount, 2)" :subtitle="$p->method.' · '.($p->transaction_id ?? '-')">
                            <x-slot:badge><x-badge :color="$color">{{ $label }}</x-badge></x-slot:badge>
                            <x-slot:footer>{{ \Illuminate\Support\Carbon::parse($p->received_at)->format('d M, g:i A') }} · {{ $p->ship_name }} · {{ __('from :n', ['n' => $p->sender_number ?? '-']) }}</x-slot:footer>
                            @if ($status === 'check')
                                <x-slot:actions><input type="checkbox" name="ids[]" value="{{ $p->id }}" x-model="picked" class="h-5 w-5 rounded border-gray-300 text-primary"></x-slot:actions>
                            @endif
                        </x-record-card>
                    </label>
                @endforeach
            </x-list.cards>
        </form>
        <x-confirm-modal id="pay-reject" :verb="__('Reject')" :message="__('The ticked payments did not match the statement. Their orders get the full COD back and the moderators are told.')" />

        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</x-layouts.app>
