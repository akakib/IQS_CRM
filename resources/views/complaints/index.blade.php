@php
    use App\Support\Permissions\Mask;
    $tabUrl = fn ($t) => request()->fullUrlWithQuery(['tab' => $t, 'page' => null]);
    $stages = ['' => __('Any stage'), 'none' => __('Nobody'), 'sales' => __('Sales'), 'verification' => __('Verification'), 'packing' => __('Packing'), 'dispatch' => __('Dispatch'), 'courier' => __('Courier'), 'customer' => __('Customer')];
    $resolutions = ['solved' => __('Solved'), 'refunded' => __('Refunded'), 'replacement' => __('Replacement'), 'rejected' => __('Rejected')];
@endphp

<x-layouts.app :heading="__('Complaints')">
    <div class="mb-3 flex items-center justify-between gap-3">
        <x-tabs class="mb-0 flex-1" :tabs="[
            'open' => [__('Open'), $tabUrl('open'), $counts['open']],
            'mine' => [__('Mine'), $tabUrl('mine'), $counts['mine']],
            'all' => [__('All'), $tabUrl('all')],
        ]" :active="$tab" />
        @can('complaints.create')
            <x-button :href="route('complaints.create')" class="shrink-0">+ {{ __('New complaint') }}</x-button>
        @endcan
    </div>

    <x-list.filter-bar :list="$list" :action="route('complaints.index')" :placeholder="__('Order no, phone, name or #id')">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if ($tab === 'all')
            <x-simple-select name="status" :options="['' => __('Any status'), 'open' => __('Open'), 'resolved' => __('Resolved')]" :value="$list->filter('status') ?? ''" />
        @endif
        <x-simple-select name="category" :options="['' => __('Any type')] + $categories" :value="$list->filter('category') ?? ''" />
        <x-simple-select name="stage" :options="$stages" :value="$list->filter('stage') ?? ''" />
        @if ($staff)
            <x-simple-select name="assigned" :options="['' => __('Anyone')] + $staff" :value="$list->filter('assigned') ?? ''" />
        @endif
        <x-date-range :from="$list->filter('from')" :to="$list->filter('to')" />
    </x-list.filter-bar>

    @if ($complaints->isEmpty())
        <x-empty-state :message="$tab === 'mine' ? __('No open complaint is assigned to you.') : __('No complaints here.')" :action="auth()->user()->can('complaints.create') ? route('complaints.create') : null" :action-label="__('Open a complaint')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th><x-list.sort :list="$list" column="id">#</x-list.sort></th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Assigned to') }}</th>
                <th><x-list.sort :list="$list" column="sla_due_at">{{ __('Due') }}</x-list.sort></th>
                <th>{{ __('Opened') }}</th>
            </x-slot:head>
            @foreach ($complaints as $c)
                @php($due = $c->sla_due_at ? \Illuminate\Support\Carbon::parse($c->sla_due_at) : null)
                <tr class="cursor-pointer" onclick="location.href='{{ route('complaints.show', $c->id) }}'">
                    <td><span class="font-mono font-medium text-gray-800">#{{ $c->id }}</span>@if ($c->order_no) <span class="font-mono text-xs text-gray-400">{{ $c->order_no }}</span>@endif</td>
                    <td><p class="text-gray-800">{{ $c->customer_name }}</p><p class="font-mono text-xs text-gray-500">{{ Mask::value($c->customer_phone, 'customer_contact') }}</p></td>
                    <td class="text-gray-700">{{ $c->category }}<span class="block text-xs text-gray-400">{{ __('via') }} {{ $c->source }}</span></td>
                    <td>
                        @if ($c->status === 'resolved')
                            <x-badge color="green">{{ $resolutions[$c->resolution] ?? __('Resolved') }}</x-badge>
                        @elseif ($due && $due->isPast())
                            <x-badge color="red">{{ __('Overdue') }}</x-badge>
                        @else
                            <x-badge color="amber">{{ __('Open') }}</x-badge>
                        @endif
                    </td>
                    <td class="text-gray-600">{{ $c->assignee ?? '-' }}</td>
                    <td class="text-gray-500">{{ $c->status === 'open' && $due ? $due->diffForHumans() : '-' }}</td>
                    <td class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($c->created_at)->format('d M, g:i A') }}</td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($complaints as $c)
                @php($due = $c->sla_due_at ? \Illuminate\Support\Carbon::parse($c->sla_due_at) : null)
                <a href="{{ route('complaints.show', $c->id) }}" class="block">
                    <x-record-card :title="'#'.$c->id.' · '.$c->customer_name" :subtitle="$c->category.($c->order_no ? ' · '.$c->order_no : '')">
                        <x-slot:badge>
                            @if ($c->status === 'resolved')<x-badge color="green">{{ $resolutions[$c->resolution] ?? __('Resolved') }}</x-badge>
                            @elseif ($due && $due->isPast())<x-badge color="red">{{ __('Overdue') }}</x-badge>
                            @else<x-badge color="amber">{{ __('Open') }}</x-badge>@endif
                        </x-slot:badge>
                        <x-slot:footer>{{ \Illuminate\Support\Carbon::parse($c->created_at)->format('d M, g:i A') }} · {{ $c->assignee ?? __('Nobody') }}</x-slot:footer>
                    </x-record-card>
                </a>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $complaints->links() }}</div>
    @endif
</x-layouts.app>
