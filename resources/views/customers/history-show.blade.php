@php
    $labels = ['orders' => __('Orders imported'), 'known' => __('For known customers'), 'new' => __('New customers'), 'in_iqs' => __('Already in IQS'),
        'already' => __('Imported before'), 'skipped' => __('Skipped by status'), 'bad_phone' => __('Bad phone'), 'bad_date' => __('Bad date')];
@endphp

<x-layouts.app :heading="__('Import order history')">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('customers.history') }}" class="inline-block text-sm text-primary hover:underline">{{ __('Back to imports') }}</a>
        <x-card :title="$import->file_name" :subtitle="__(':s · :n rows · by :b', ['s' => $import->source, 'n' => $import->row_count, 'b' => $import->by ?? '-'])">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($labels as $k => $label)
                    <div class="rounded-xl bg-gray-50 p-3">
                        <p class="text-xs text-gray-500">{{ $label }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums {{ in_array($k, ['bad_phone', 'bad_date'], true) && ($result[$k] ?? 0) ? 'text-red-700' : 'text-gray-900' }}">{{ number_format($result[$k] ?? 0) }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>
        @if ($recent->isNotEmpty())
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
