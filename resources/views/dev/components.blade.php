@php
    $demoList = \App\Support\Lists\ListState::from(request(), ['name'], ['status' => ['active', 'inactive']]);
    $section = 'mb-8';
    $h = 'mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500';
@endphp

<x-layouts.app :heading="__('Component library')">
    <p class="mb-6 text-sm text-gray-500">{{ __('Every shared Blade + Alpine component in all its states. Local only. Pages are built from these; no page writes its own copy.') }}</p>

    <section class="{{ $section }}">
        <p class="{{ $h }}">Buttons</p>
        <div class="flex flex-wrap items-center gap-2">
            <x-button type="button">Primary</x-button>
            <x-button type="button" variant="secondary">Secondary</x-button>
            <x-button type="button" variant="danger">Danger</x-button>
            <x-button type="button" variant="ghost">Ghost</x-button>
            <x-button type="button" size="sm">Small</x-button>
            <x-button type="button" disabled>Disabled</x-button>
        </div>
    </section>

    <section class="{{ $section }}">
        <p class="{{ $h }}">Badges / status chips</p>
        <div class="flex flex-wrap gap-2">
            @foreach (['green', 'red', 'amber', 'blue', 'purple', 'gray'] as $c)
                <x-badge :color="$c">{{ ucfirst($c) }}</x-badge>
            @endforeach
            <x-badge color="#7c3aed">From DB #7c3aed</x-badge>
            <x-status-badge :active="true" /><x-status-badge :active="false" />
        </div>
    </section>

    <section class="{{ $section }} grid gap-4 md:grid-cols-2">
        <x-card title="Form fields" subtitle="x-form.input, x-simple-select, x-search-select, x-date-range">
            <x-form.input name="demo_name" label="Text input" placeholder="Type here" />
            <x-form.input name="demo_err" label="With error" value="bad" />
            <p class="mb-2 text-sm font-medium text-gray-700">Simple select</p>
            <x-simple-select name="demo_s" :options="['a' => 'Option A', 'b' => 'Option B']" value="a" full-width class="mb-4 w-full" />
            <p class="mb-2 text-sm font-medium text-gray-700">Search select (server-side, max 20)</p>
            <x-search-select name="demo_user" :url="route('dev.search-demo')" class="mb-4" />
            <p class="mb-2 text-sm font-medium text-gray-700">Date range</p>
            <x-date-range />
        </x-card>

        <x-card title="Stat tiles">
            <div class="grid grid-cols-2 gap-3">
                <x-stat-tile label="Orders today" value="128" hint="+12 vs yesterday" trend="up" />
                <x-stat-tile label="Returns" value="4" hint="3.1%" trend="down" />
                <x-stat-tile label="Ad spend" value="$212" hint="৳26,500" />
                <x-stat-tile label="Clickable" value="57" href="#" />
            </div>
        </x-card>
    </section>

    <section class="{{ $section }} grid gap-4 md:grid-cols-2">
        <x-card title="Overlays">
            <div class="flex flex-wrap gap-2">
                <x-button type="button" variant="secondary" @click="$dispatch('open-modal', 'demo-modal')">Modal</x-button>
                <x-button type="button" variant="secondary" @click="$dispatch('open-modal', 'demo-slide')">Slide-over</x-button>
                <x-button type="button" variant="secondary" @click="$dispatch('open-confirm', { id: 'demo-confirm', label: 'Order #1001' })">Confirm dialog</x-button>
                <x-button type="button" variant="secondary" @click="window.toast('Saved')">Toast</x-button>
                <x-button type="button" variant="secondary" @click="window.toast('Could not save', 'error')">Error toast</x-button>
                <x-dropdown><x-slot:trigger>Dropdown</x-slot:trigger><a href="#">Export</a><button type="button">Print</button></x-dropdown>
            </div>
        </x-card>

        <x-card title="Loading and empty">
            <x-skeleton lines="4" class="mb-4" />
            <x-empty-state message="No orders on hold." action="#" action-label="Go to all orders" />
        </x-card>
    </section>

    <section class="{{ $section }}">
        <p class="{{ $h }}">Tabs</p>
        <x-tabs :tabs="['all' => ['All', '#', 120], 'hold' => ['Hold', '#', 7], 'repack' => ['Repack', '#', 2]]" active="hold" />
    </section>

    <section class="{{ $section }}">
        <p class="{{ $h }}">Index page pattern: filter bar + table (desktop) / cards + bottom sheet (phone) + bulk bar</p>
        <x-list.filter-bar :list="$demoList" :action="url()->current()" placeholder="Search demo">
            <x-simple-select name="status" :options="['' => 'All statuses', 'active' => 'Active', 'inactive' => 'Inactive']" :value="$demoList->filter('status') ?? ''" />
        </x-list.filter-bar>
        <x-list.selectable :ids="[1, 2, 3]">
            <x-list.table>
                <x-slot:head><th class="w-8"></th><th><x-list.sort :list="$demoList" column="name">Name</x-list.sort></th><th>Status</th></x-slot:head>
                @foreach ([1 => 'Lovelane', 2 => 'Newmarket', 3 => 'Riajuddin Bazar'] as $id => $name)
                    <tr><td><x-list.check :id="$id" /></td><td>{{ $name }}</td><td><x-status-badge :active="$id !== 2" /></td></tr>
                @endforeach
            </x-list.table>
            <x-list.cards>
                @foreach ([1 => 'Lovelane', 2 => 'Newmarket', 3 => 'Riajuddin Bazar'] as $id => $name)
                    <x-record-card :title="$name" subtitle="Warehouse">
                        <x-slot:badge><x-list.check :id="$id" /></x-slot:badge>
                        <x-slot:footer>Updated today</x-slot:footer>
                        <x-slot:actions><a href="#" class="text-sm text-green-900">Edit</a></x-slot:actions>
                    </x-record-card>
                @endforeach
            </x-list.cards>
            <x-slot:actions><button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs" @click="window.toast(selected.length + ' would be updated')">Bulk action</button></x-slot:actions>
        </x-list.selectable>
    </section>

    <section class="{{ $section }}" x-data @scan="$dispatch('scan-result', { ok: $event.detail.startsWith('IQS'), message: $event.detail.startsWith('IQS') ? 'Packed ' + $event.detail : 'Unknown label ' + $event.detail })">
        <p class="{{ $h }}">Barcode scan input (type IQS-1001 + Enter for success, anything else for error)</p>
        <div class="max-w-md"><x-scan-input /></div>
    </section>

    <section class="{{ $section }}">
        <p class="{{ $h }}">Notification bell</p>
        <p class="text-sm text-gray-500">Lives in the top bar (top right of this page).</p>
    </section>

    <x-modal id="demo-modal" title="Modal title">
        Modal body. Bottom sheet on phones, centered on desktop.
        <x-slot:footer><x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'demo-modal')">Close</x-button></x-slot:footer>
    </x-modal>
    <x-slide-over id="demo-slide" title="Order #1001">Slide-over body, e.g. an order preview.</x-slide-over>
    <x-confirm-modal id="demo-confirm" verb="Cancel order" message="The customer will be notified." />
</x-layouts.app>
