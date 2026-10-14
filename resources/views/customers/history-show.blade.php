@php
    $labels = ['orders' => __('Orders imported'), 'known' => __('For known customers'), 'new' => __('New customers'), 'in_iqs' => __('Already in IQS'),
        'updated' => __('Updated (changed since)'), 'already' => __('Imported before, no change'), 'skipped' => __('Skipped by status'), 'bad_phone' => __('Bad phone'), 'no_name' => __('No name'), 'bad_date' => __('Bad date')];
@endphp

<x-layouts.app :heading="__('Import order history')">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('customers.history') }}" class="inline-block text-sm text-primary hover:underline">{{ __('Back to imports') }}</a>
        @if ($import->undone_at)
            <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('This import was taken back on :d. Its orders and the customers it created are gone.', ['d' => \Illuminate\Support\Carbon::parse($import->undone_at)->format('d M, g:i A')]) }}</p>
        @endif
        <x-card :title="$import->file_name" :subtitle="__(':s · :n rows · by :b', ['s' => $import->source, 'n' => $import->row_count, 'b' => $import->by ?? '-'])">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
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
                <x-confirm-modal id="history-undo-confirm" :verb="__('Undo')" :message="__('Removes the orders this import brought in, and the customers it created that have no other orders. Customers who already had orders stay.')" />
            </div>
        @endif
        @if ($recent->isNotEmpty() && ! $import->undone_at)
            <x-card :title="__('Some of them')">
                @foreach ($recent as $p)
                    <a href="{{ route('journey.show', $p->id) }}" class="flex items-center justify-between gap-3 border-b border-gray-100 py-2.5 text-sm last:border-0 hover:bg-gray-50">
                        <span class="min-w-0"><span class="block truncate font-medium text-gray-800">{{ $p->name }}</span><span class="text-xs text-gray-500">{{ $p->external_no ?? '-' }} · {{ \Illuminate\Support\Carbon::parse($p->ordered_at)->format('d M Y') }} · {{ __(\App\Services\Customers\HistoryImport::STATUSES[$p->status] ?? $p->status) }}</span></span>
                        <span class="shrink-0 font-semibold tabular-nums">৳{{ number_format((float) $p->amount) }}</span>
                    </a>
                @endforeach
            </x-card>
        @endif
    </div>
</x-layouts.app>
