@php
    $tk = fn ($v) => '৳'.number_format((float) $v);
    $tabs = [
        'waiting' => [__('Not paid yet'), request()->fullUrlWithQuery(['tab' => 'waiting', 'page' => null]), (int) $waiting->n],
        'mismatch' => [__('Paid a different amount'), request()->fullUrlWithQuery(['tab' => 'mismatch', 'page' => null]), $mismatchCount ?: null],
        'paid' => [__('Paid'), request()->fullUrlWithQuery(['tab' => 'paid', 'page' => null])],
        'statements' => [__('Statements'), request()->fullUrlWithQuery(['tab' => 'statements', 'page' => null])],
    ];
@endphp

<x-layouts.app :heading="__('COD from courier')">
    @include('payments._tabs', ['active' => 'cod'])

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="text-sm text-gray-600">
            <p>{{ __('Is the cash the courier collected actually paid to us? Upload each Steadfast payment statement (CSV) and every parcel is checked.') }}</p>
            <p class="mt-1">{{ __('Still owed by the courier:') }} <b class="tabular-nums text-gray-900">{{ $tk($waiting->collected - $waiting->charge) }}</b>
                <span class="text-xs text-gray-500">({{ __(':n parcels, collected :c, less charge :d', ['n' => $waiting->n, 'c' => $tk($waiting->collected), 'd' => $tk($waiting->charge)]) }})</span></p>
        </div>
        <x-button type="button" size="sm" class="shrink-0" @click="$dispatch('open-modal', 'cod-upload')">{{ __('Upload statement') }}</x-button>
    </div>

    <x-modal id="cod-upload" :title="__('Upload payment statement')" persistent>
        <form method="POST" action="{{ route('cod.upload') }}" enctype="multipart/form-data" id="cod-upload-form" class="space-y-3 text-sm">
            @csrf
            <p class="text-gray-500">{{ __('In the Steadfast panel open the payment, download its parcel list and save it as CSV. Columns are found by name: consignment id (or tracking code or invoice) and collected amount; a charge column is read too.') }}</p>
            <input type="file" name="file" accept=".csv,text/csv" required class="block w-full text-sm">
            <div>
                <p class="mb-1 font-medium text-gray-700">{{ __('Paid on') }}</p>
                <x-date-input name="paid_on" :value="today()->toDateString()" :max="today()->toDateString()" :clearable="false" full-width />
            </div>
            <label class="block font-medium text-gray-700">{{ __('Payment reference (optional)') }}
                <input name="reference" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
            </label>
            @error('file')<p class="text-red-600">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer>
            <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'cod-upload')">{{ __('Cancel') }}</x-button>
            <x-button type="submit" form="cod-upload-form">{{ __('Upload') }}</x-button>
        </x-slot:footer>
    </x-modal>
    @if ($errors->has('file'))
        <div x-data x-init="$dispatch('open-modal', 'cod-upload')"></div>
    @endif

    <x-tabs :tabs="$tabs" :active="$tab" />

    @if ($tab !== 'statements')
        <x-list.filter-bar :list="$list" :action="route('cod.index')" :placeholder="__('Order no, CN or tracking code')">
            <input type="hidden" name="tab" value="{{ $tab }}">
        </x-list.filter-bar>
    @endif

    @if ($rows->isEmpty())
        <x-empty-state :message="match ($tab) { 'waiting' => __('Every delivered parcel is paid.'), 'mismatch' => __('No payout differs from what was collected.'), 'statements' => __('No statement uploaded yet.'), default => __('Nothing here.') }" />
    @elseif ($tab === 'statements')
        <div class="space-y-2">
            @foreach ($rows as $p)
                <div class="flex flex-col gap-1 rounded-xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900">{{ \Illuminate\Support\Carbon::parse($p->paid_on)->format('d M Y') }}@if ($p->reference) · <span class="font-mono">{{ $p->reference }}</span>@endif</p>
                        <p class="text-xs text-gray-500">{{ $p->file_name }} · {{ $p->by ?? '-' }} · {{ __(':m matched, :x different, :u not found', ['m' => $p->matched, 'x' => $p->mismatched, 'u' => $p->unknown]) }}</p>
                    </div>
                    <p class="font-semibold tabular-nums text-gray-900">{{ $tk($p->total_collected - $p->total_charge) }}</p>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    @else
        <div class="space-y-2">
            @foreach ($rows as $r)
                @php
                    $days = (int) \Illuminate\Support\Carbon::parse($r->final_at)->startOfDay()->diffInDays(today());
                    $late = $tab === 'waiting' && $days > $lateDays;
                @endphp
                <div @class(['flex flex-col gap-2 rounded-xl border bg-white p-4 sm:flex-row sm:items-center sm:gap-3', 'border-red-200' => $late || $tab === 'mismatch', 'border-gray-200' => ! ($late || $tab === 'mismatch')])>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900"><a href="{{ route('orders.show', $r->order_id) }}" class="font-mono hover:underline">{{ $r->order_no }}</a> · {{ $r->ship_name }}</p>
                        <p class="text-xs text-gray-500">CN <span class="font-mono">{{ $r->consignment_id ?? '-' }}</span> · {{ __('delivered :d', ['d' => \Illuminate\Support\Carbon::parse($r->final_at)->format('d M')]) }}
                            @if ($tab === 'waiting')· <span @class(['font-medium text-red-600' => $late])>{{ trans_choice(':count day waiting|:count days waiting', $days, ['count' => $days]) }}</span>@endif
                            @if ($r->paid_on)· {{ __('paid :d', ['d' => \Illuminate\Support\Carbon::parse($r->paid_on)->format('d M')]) }}@endif</p>
                    </div>
                    <div class="text-right text-sm tabular-nums">
                        <p class="text-gray-900">{{ __('Collected') }} <b>{{ $tk($r->collected_amount) }}</b></p>
                        @if ($tab === 'mismatch')
                            <p class="text-red-600">{{ __('Statement') }} <b>{{ $tk($r->payout_collected) }}</b> · {{ __('short :a', ['a' => $tk($r->collected_amount - $r->payout_collected)]) }}</p>
                        @elseif ($r->payout_charge !== null || $r->delivery_charge !== null)
                            <p class="text-xs text-gray-500">{{ __('charge :c', ['c' => $tk($r->payout_charge ?? $r->delivery_charge)]) }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</x-layouts.app>
