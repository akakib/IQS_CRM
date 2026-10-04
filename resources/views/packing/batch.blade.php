<x-layouts.app :heading="__('Batch :b', ['b' => $batch->batch_no])">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('packing.index') }}" class="text-sm text-green-900 hover:underline">{{ __('Packing') }}</a>
        <x-badge :color="['released' => 'blue', 'picked' => 'amber', 'done' => 'green'][$batch->status]">{{ ucfirst($batch->status) }}</x-badge>
        <span class="ml-auto flex gap-2">
            <x-button size="sm" variant="secondary" type="button" onclick="window.print()">{{ __('Print pick list') }}</x-button>
            @if ($batch->status === 'released')
                <form method="POST" action="{{ route('packing.picked', $batch->id) }}">@csrf<x-button size="sm" variant="secondary">{{ __('Picked') }}</x-button></form>
            @endif
            @if ($batch->status !== 'done')
                <form method="POST" action="{{ route('packing.done', $batch->id) }}">@csrf<x-button size="sm">{{ __('Packing finished') }}</x-button></form>
            @endif
        </span>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-card :title="__('Pick list (by shelf)')">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-gray-500"><tr><th class="py-1 pr-2">{{ __('Shelf') }}</th><th class="py-1 pr-2">{{ __('Item') }}</th><th class="py-1 pr-2 text-right">{{ __('Total') }}</th><th class="py-1 text-right">{{ __('Orders') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($lines as $l)
                        <tr>
                            <td class="py-2 pr-2 font-mono text-xs">{{ $l->shelf_code ?? '-' }}</td>
                            <td class="py-2 pr-2">{{ $l->name }} <span class="font-mono text-xs text-gray-400">{{ $l->sku }}</span></td>
                            <td class="py-2 pr-2 text-right font-semibold tabular-nums">{{ rtrim(rtrim(number_format((float) $l->qty, 3, '.', ''), '0'), '.') }}{{ $l->unit === 'g' ? ' g' : '' }}</td>
                            <td class="py-2 text-right tabular-nums text-gray-500">{{ $l->orders }}</td>
                            <td class="py-2 pl-2 text-right">
                                <form method="POST" action="{{ route('packing.report') }}">
                                    @csrf
                                    <input type="hidden" name="variant_id" value="{{ $l->variant_id }}">
                                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                                    <button class="text-xs text-red-600 hover:underline" title="{{ __('Report to Admin') }}">{{ __('Not found') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        <x-card :title="__('Orders in this batch')">
            @foreach ($orders as $o)
                <div @class(['border-b border-gray-50 py-2 text-sm last:border-0', 'opacity-50' => $o->stock_issue_flag])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono font-medium">{{ $o->order_no }}</span>
                        <span class="flex items-center gap-1">
                            @if ($o->stock_issue_flag)<x-badge color="red">{{ __('Skip: item missing') }}</x-badge>@endif
                            <x-order-status :order="$o" :statuses="$statuses" />
                        </span>
                    </div>
                    <p class="text-xs text-gray-600">{{ $o->items->map(fn ($i) => $i->name_snapshot.' ×'.rtrim(rtrim($i->qty, '0'), '.'))->join(', ') }}</p>
                </div>
            @endforeach
        </x-card>
    </div>
</x-layouts.app>
