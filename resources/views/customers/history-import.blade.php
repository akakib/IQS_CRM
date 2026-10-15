<x-layouts.app :heading="__('Import order history')">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('customers.index') }}" class="inline-block text-sm text-primary hover:underline">{{ __('Back to customers') }}</a>

        <x-card :title="__('Easiest: fill the sample file')">
            <p class="text-sm text-gray-600">{{ __('Download the sample, fill one row per order and upload it below. Its columns are matched by themselves: Name, Phone, CN, Address, Products, Amount, Date, Status.') }}</p>
            <ul class="mt-2 list-inside list-disc space-y-0.5 text-xs text-gray-500">
                <li>{{ __('Name and Phone are a must. Any phone form works: 01712345678, +8801712345678 or Bangla digits.') }}</li>
                <li>{{ __('CN: the courier consignment id, if there is one. The same CN is never imported twice.') }}</li>
                <li>{{ __('Date: when they ordered (2026-07-24 or 24/07/2026). Leave it empty if unknown; the import day is used.') }}</li>
                <li>{{ __('Status: Delivered, Returned, Cancelled or Refunded. Empty means Delivered.') }}</li>
                <li>{{ __('Replace the example row: its phone is not real, so it is skipped.') }}</li>
            </ul>
            <x-button variant="secondary" :href="route('customers.history.sample')" class="mt-3">{{ __('Download the sample file') }}</x-button>
        </x-card>

        <x-card :title="__('Upload a CSV or Excel file')">
            <p class="mb-3 text-sm text-gray-600">{{ __('The filled sample file. It imports straight away; if something is wrong, Undo it on the next page and upload again.') }}</p>
            <p class="mb-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">{{ __('These orders only go into each customer\'s journey. They do not touch stock, money, the courier or points. New phone numbers become new customers.') }}</p>
            <form method="POST" action="{{ route('customers.history.store') }}" enctype="multipart/form-data" class="space-y-3" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <input type="file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
                    class="block w-full rounded-xl border border-dashed border-gray-300 p-3 text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm">
                @error('file')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <x-button x-bind:disabled="busy"><span x-text="busy ? @js(__('Reading the file...')) : @js(__('Upload'))"></span></x-button>
            </form>
        </x-card>

        <x-card :title="__('Recent imports')">
            @forelse ($imports as $i)
                @php $r = json_decode((string) $i->result, true) ?: []; @endphp
                <a href="{{ route('customers.history.show', $i->id) }}" class="flex items-center justify-between gap-3 border-b border-gray-100 py-2.5 text-sm last:border-0 hover:bg-gray-50">
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-gray-800">{{ $i->file_name }}</span>
                        <span class="text-xs text-gray-500">{{ $i->source ?? __('Not mapped yet') }} · {{ $i->by }} · {{ \Illuminate\Support\Carbon::parse($i->created_at)->format('d M, g:i A') }}</span>
                    </span>
                    <span class="shrink-0 text-right text-xs">
                        @if ($i->done_at)<span class="text-green-700">{{ __(':n orders', ['n' => $r['orders'] ?? 0]) }}</span>@else<span class="text-amber-700">{{ __('Finish mapping') }}</span>@endif
                    </span>
                </a>
            @empty
                <p class="text-sm text-gray-500">{{ __('Nothing imported yet.') }}</p>
            @endforelse
        </x-card>
    </div>
</x-layouts.app>
