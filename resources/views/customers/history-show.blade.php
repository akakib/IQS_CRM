@php
    $labels = ['orders' => __('Orders imported'), 'known' => __('For known customers'), 'new' => __('New customers'), 'in_iqs' => __('Already in IQS'),
        'updated' => __('Updated (changed since)'), 'already' => __('Imported before, no change'), 'skipped' => __('Skipped by status'), 'bad_phone' => __('Bad phone'), 'no_name' => __('No name'), 'bad_date' => __('Bad date')];
@endphp

<x-layouts.app :heading="__('Import order history')">
    <div class="mx-auto max-w-6xl space-y-4">
        <a href="{{ route('customers.history') }}" class="inline-block text-sm text-primary hover:underline">{{ __('Back to imports') }}</a>
        @if ($import->undone_at)
            <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('This import was taken back on :d. Its orders and the customers it created are gone.', ['d' => \Illuminate\Support\Carbon::parse($import->undone_at)->format('d M, g:i A')]) }}</p>
        @endif
        <x-card :title="$import->file_name" :subtitle="__(':s · :n rows · by :b', ['s' => $import->source, 'n' => $import->row_count, 'b' => $import->by ?? '-'])">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-5">
                @foreach ($labels as $k => $label)
                    <div class="rounded-xl bg-gray-50 p-3">
                        <p class="text-xs text-gray-500">{{ $label }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums {{ in_array($k, ['bad_phone', 'no_name', 'bad_date'], true) && ($result[$k] ?? 0) ? 'text-red-700' : 'text-gray-900' }}">{{ number_format($result[$k] ?? 0) }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>
        @if ($import->done_at && ! $import->undone_at)
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-700">{{ __('Wrong columns? Take this import back and upload the file again.') }}</p>
                <form method="POST" action="{{ route('customers.history.undo', $import->id) }}" id="history-undo" class="mt-3">@csrf
                    <button type="button" class="rounded-xl border border-red-300 px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50"
                        @click="$dispatch('open-confirm', { id: 'history-undo-confirm', form: 'history-undo', label: @js($import->file_name) })">{{ __('Undo this import') }}</button>
                </form>
                <x-confirm-modal id="history-undo-confirm" :verb="__('Undo')" :message="__('Removes the orders this import brought in, and the customers it created that have no other orders. Customers who already had orders stay, and what it changed on earlier parcels is put back.')" />
            </div>
        @endif
        @if ($import->done_at && ! $import->undone_at)
            @php
                $statusLabels = \App\Services\Customers\HistoryImport::STATUSES;
                $canJourney = auth()->user()->can('customers.view');
                $badge = ['delivered' => 'green', 'returned' => 'red', 'cancelled' => 'red', 'refunded' => 'amber', 'pending' => 'gray'];
            @endphp
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm font-semibold text-gray-900">{{ __('Orders in this import') }}</p>
                    <p class="text-xs text-gray-500">{{ trans_choice(':count order|:count orders', $orders->total(), ['count' => number_format($orders->total())]) }}</p>
                </div>
                <form method="GET" action="{{ route('customers.history.show', $import->id) }}" class="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
                    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('Name, phone, CN, address or product') }}" autocomplete="off"
                        class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-base focus:border-primary focus:outline-none">
                    <x-simple-select name="status" :options="['' => __('All statuses')] + array_map(fn ($l) => __($l), array_diff_key($statusLabels, ['skip' => 1]))" :value="$filters['status']" full-width
                        x-on:select-change="$nextTick(() => $el.closest('form').submit())" />
                    <x-simple-select name="sort" :options="array_map(fn ($l) => __($l), \App\Http\Controllers\CustomerImportController::SORTS)" :value="$filters['sort']" full-width
                        x-on:select-change="$nextTick(() => $el.closest('form').submit())" />
                    <div class="flex gap-2">
                        <x-button class="flex-1">{{ __('Search') }}</x-button>
                        @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['sort'] !== 'new')
                            <x-button variant="secondary" :href="route('customers.history.show', $import->id)">{{ __('Clear') }}</x-button>
                        @endif
                    </div>
                </form>

                @if ($orders->isEmpty())
                    <p class="mt-4 rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">{{ __('No order matches.') }}</p>
                @else
                    {{-- Phone: cards. --}}
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:hidden">
                        @foreach ($orders as $p)
                            <div class="rounded-xl border border-gray-100 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        @if ($canJourney)<a href="{{ route('journey.show', $p->id) }}" class="block truncate font-medium text-gray-900 hover:text-primary">{{ $p->name }}</a>@else<p class="truncate font-medium text-gray-900">{{ $p->name }}</p>@endif
                                        <p class="font-mono text-xs text-gray-500">{{ \App\Support\Permissions\Mask::value($p->primary_phone, 'customer_contact') }}@if ($p->cn) · {{ $p->cn }}@elseif ($p->external_no) · #{{ $p->external_no }}@endif</p>
                                    </div>
                                    <p class="shrink-0 text-right"><span class="font-semibold tabular-nums">৳{{ number_format((float) $p->amount) }}</span><br><x-badge :color="$badge[$p->status] ?? 'gray'">{{ __($statusLabels[$p->status] ?? $p->status) }}</x-badge></p>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($p->ordered_at)->format('d M Y') }}@if ($p->items) · {{ $p->items }}@endif</p>
                                @if ($p->address)<p class="truncate text-xs text-gray-400">{{ $p->address }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                    {{-- Wider screens: a table. --}}
                    <div class="-mx-4 mt-3 hidden overflow-x-auto px-4 lg:block">
                        <table class="w-full min-w-[56rem] text-sm">
                            <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                                <th class="py-2 pr-3 font-medium">{{ __('Customer') }}</th><th class="py-2 pr-3 font-medium">{{ __('Phone') }}</th><th class="py-2 pr-3 font-medium">{{ __('CN') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ __('Date') }}</th><th class="py-2 pr-3 font-medium">{{ __('Products') }}</th><th class="py-2 pr-3 font-medium">{{ __('Status') }}</th><th class="py-2 text-right font-medium">{{ __('Amount') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($orders as $p)
                                    <tr class="border-b border-gray-100 align-top last:border-0">
                                        <td class="py-2 pr-3">@if ($canJourney)<a href="{{ route('journey.show', $p->id) }}" class="font-medium text-gray-900 hover:text-primary">{{ $p->name }}</a>@else<span class="font-medium text-gray-900">{{ $p->name }}</span>@endif
                                            @if ($p->address)<span class="block max-w-xs truncate text-xs text-gray-400">{{ $p->address }}</span>@endif</td>
                                        <td class="whitespace-nowrap py-2 pr-3 font-mono text-gray-700">{{ \App\Support\Permissions\Mask::value($p->primary_phone, 'customer_contact') }}</td>
                                        <td class="whitespace-nowrap py-2 pr-3 font-mono text-gray-700">{{ $p->cn ?? ($p->external_no ? '#'.$p->external_no : '-') }}</td>
                                        <td class="whitespace-nowrap py-2 pr-3 text-gray-700">{{ \Illuminate\Support\Carbon::parse($p->ordered_at)->format('d M Y') }}</td>
                                        <td class="max-w-xs py-2 pr-3 text-gray-600">{{ $p->items ?? '-' }}</td>
                                        <td class="py-2 pr-3"><x-badge :color="$badge[$p->status] ?? 'gray'">{{ __($statusLabels[$p->status] ?? $p->status) }}</x-badge></td>
                                        <td class="whitespace-nowrap py-2 text-right font-semibold tabular-nums">৳{{ number_format((float) $p->amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $orders->links() }}</div>
                @endif
            </div>

            @if ($problems)
                @php $why = ['bad_phone' => __('Phone not usable'), 'no_name' => __('No name'), 'bad_date' => __('Date not readable'), 'skipped' => __('Skipped by status')]; @endphp
                <details class="rounded-2xl border border-red-200 bg-white p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-red-800">{{ __('Rows that did not come in (:n)', ['n' => count($problems)]) }}</summary>
                    <p class="mt-2 text-xs text-gray-500">{{ __('Fix these rows in the file and upload it again: rows already in come in once, only the fixed ones are added.') }}</p>
                    <div class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($problems as $pr)
                            <div class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                                <span class="min-w-0"><span class="text-xs text-gray-400">{{ __('Row :n', ['n' => $pr['line']]) }}</span> <span class="font-medium text-gray-900">{{ $pr['name'] ?: '-' }}</span>
                                    <span class="font-mono text-xs text-gray-600">{{ $pr['phone'] ?: __('no phone') }}</span></span>
                                <x-badge color="red">{{ $why[$pr['why']] ?? $pr['why'] }}</x-badge>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        @endif
    </div>
</x-layouts.app>
