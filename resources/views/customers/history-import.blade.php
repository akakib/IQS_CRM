<x-layouts.app :heading="__('Import order history')">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('customers.index') }}" class="inline-block text-sm text-primary hover:underline">{{ __('Back to customers') }}</a>

        <x-card :title="__('Upload a CSV or Excel file')">
            <p class="mb-3 text-sm text-gray-600">{{ __('Any order export works: WooCommerce, Pathao, Steadfast or your own sheet. Next you tell IQS which column is which; a file of the same shape is matched by itself next time.') }}</p>
            <p class="mb-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">{{ __('These orders only go into each customer\'s journey. They do not touch stock, money, the courier or points. New phone numbers become new customers.') }}</p>
            <form method="POST" action="{{ route('customers.history.store') }}" enctype="multipart/form-data" class="space-y-3" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <input type="file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
                    class="block w-full rounded-xl border border-dashed border-gray-300 p-3 text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm">
                @error('file')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <x-button x-bind:disabled="busy"><span x-text="busy ? @js(__('Reading the file...')) : @js(__('Upload and map columns'))"></span></x-button>
            </form>
        </x-card>

        <x-card :title="__('Recent imports')">
            @forelse ($imports as $i)
                @php $r = json_decode((string) $i->result, true) ?: []; @endphp
                <a href="{{ $i->done_at ? route('customers.history.show', $i->id) : route('customers.history.map', $i->id) }}" class="flex items-center justify-between gap-3 border-b border-gray-100 py-2.5 text-sm last:border-0 hover:bg-gray-50">
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
