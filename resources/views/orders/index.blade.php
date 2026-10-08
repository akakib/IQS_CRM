@php
    use App\Support\Permissions\Mask;
    $tabUrl = fn ($t) => request()->fullUrlWithQuery(['tab' => $t, 'page' => null]);
    $statusOptions = ['' => __('Any status')] + collect($statuses)->filter(fn ($s) => $s['key'])->mapWithKeys(fn ($s) => [$s['key'] => __($s['name'])])->all();
@endphp

<x-layouts.app :heading="__('Orders')">
    <div class="mb-3 flex items-center justify-between gap-3">
        <x-tabs class="mb-0 flex-1" :tabs="[
            'take' => [__('To take'), $tabUrl('take'), $counts['take']],
            'mine' => [__('Mine'), $tabUrl('mine'), $counts['mine']],
            'all' => [__('All'), $tabUrl('all')],
        ]" :active="$tab" />
        <x-website-sync class="hidden sm:block" />
        @can('orders.create')
            <x-button :href="route('orders.create')" class="shrink-0">+ {{ __('Quick order') }}</x-button>
        @endcan
    </div>
    <x-website-sync class="mb-3 sm:hidden" />

    <x-list.filter-bar :list="$list" :action="route('orders.index')" :placeholder="__('Order no, phone, name or CN')">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <x-simple-select name="status" :options="$statusOptions" :value="$list->filter('status') ?? ''" />
        <x-simple-select name="channel" :options="['' => __('Any channel'), 'web' => __('Website'), 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'phone' => __('Phone')]" :value="$list->filter('channel') ?? ''" />
        @if ($moderatorOptions)
            <x-simple-select name="moderator" :options="['' => __('Anyone')] + $moderatorOptions" :value="$list->filter('moderator') ?? ''" />
        @endif
        <x-date-range :from="$list->filter('from')" :to="$list->filter('to')" />
    </x-list.filter-bar>

    @if ($orders->isEmpty())
        <x-empty-state :message="$tab === 'take' ? __('No order is waiting to be taken.') : __('No orders here.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th><x-list.sort :list="$list" column="id">{{ __('Order') }}</x-list.sort></th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Assigned to') }}</th>
                <th class="text-right"><x-list.sort :list="$list" column="grand_total">{{ __('Total') }}</x-list.sort></th>
                <th>{{ __('Placed') }}</th>
            </x-slot:head>
            @foreach ($orders as $o)
                <tr class="cursor-pointer" onclick="location.href='{{ route('orders.show', $o) }}'">
                    <td><span class="font-mono font-medium text-gray-800">{{ $o->order_no }}</span> <span class="text-xs text-gray-400">{{ $o->channel }}</span></td>
                    <td><p class="text-gray-800">{{ $o->ship_name }}</p><p class="font-mono text-xs text-gray-500">{{ Mask::value($o->ship_phone, 'customer_contact') }}</p></td>
                    <td><x-order-status :order="$o" :statuses="$statuses" /></td>
                    <td class="text-gray-600">{{ $o->moderator?->name ?? '-' }}</td>
                    <td class="text-right tabular-nums text-gray-800">৳{{ number_format((float) $o->grand_total) }}</td>
                    <td class="text-gray-500">{{ $o->created_at->format('d M, g:i A') }}</td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($orders as $o)
                <a href="{{ route('orders.show', $o) }}" class="block">
                    <x-record-card :title="$o->order_no.' · '.$o->ship_name" :subtitle="Mask::value($o->ship_phone, 'customer_contact')">
                        <x-slot:badge><x-order-status :order="$o" :statuses="$statuses" /></x-slot:badge>
                        <x-slot:footer>{{ $o->created_at->format('d M, g:i A') }} · {{ $o->moderator?->name ?? __('Not taken') }}</x-slot:footer>
                        <x-slot:actions><span class="font-semibold tabular-nums">৳{{ number_format((float) $o->grand_total) }}</span></x-slot:actions>
                    </x-record-card>
                </a>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</x-layouts.app>
