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
                $editStatuses = ['delivered' => __('Delivered'), 'returned' => __('Returned'), 'cancelled' => __('Cancelled'), 'refunded' => __('Refunded'), 'pending' => __('Not finished')];
                $canJourney = auth()->user()->can('customers.view');
                $badge = ['delivered' => 'green', 'returned' => 'red', 'cancelled' => 'red', 'refunded' => 'amber', 'pending' => 'gray'];
                $mask = fn ($p) => \App\Support\Permissions\Mask::value($p, 'customer_contact');
                $seePhone = auth()->user()->canSeeField('customer_contact');
                $guess = app(\App\Services\Customers\HistoryImport::class);
                // What the popup opens with, per order of the list.
                $orderFields = fn ($p) => ['name' => $p->name, 'phone' => $seePhone ? $p->primary_phone : '', 'cn' => $p->cn ?? '', 'amount' => rtrim(rtrim(number_format((float) $p->amount, 2, '.', ''), '0'), '.'),
                    'address' => $p->address ?? '', 'items' => $p->items ?? '', 'date' => substr($p->ordered_at, 0, 10), 'status' => array_key_exists($p->status, $editStatuses) ? $p->status : 'delivered'];
            @endphp

            {{-- Orders that came in: tick to delete several; Edit opens the popup. --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4" x-data="{ picked: [], ids: @js($orders->pluck('past_id')->all()), get all() { return this.ids.length && this.picked.length === this.ids.length } }">
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
                    <form method="POST" action="{{ route('customers.history.orders-delete', $import->id) }}" id="orders-delete">@csrf
                        <template x-for="id in picked" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                    </form>
                    <div class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-gray-50 px-3 py-2 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary" :checked="all" @change="picked = $event.target.checked ? [...ids] : []"> {{ __('All on this page') }}</label>
                        <span class="text-gray-500" x-text="picked.length ? @js(__(':n ticked')).replace(':n', picked.length) : ''"></span>
                        <button type="button" x-show="picked.length" x-cloak class="ml-auto rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                            @click="$dispatch('open-confirm', { id: 'orders-delete-confirm', form: 'orders-delete', label: picked.length + ' ' + @js(__('orders')) })">{{ __('Delete ticked') }}</button>
                    </div>

                    {{-- Phone and tablet: cards. --}}
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:hidden">
                        @foreach ($orders as $p)
                            <div class="rounded-xl border border-gray-100 p-3" :class="picked.includes({{ $p->past_id }}) ? 'border-primary bg-green-50/40' : ''">
                                <div class="flex items-start gap-2">
                                    <input type="checkbox" value="{{ $p->past_id }}" x-model.number="picked" class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-primary">
                                    <div class="min-w-0 flex-1">
                                        @if ($canJourney)<a href="{{ route('customers.show', $p->id) }}" class="block truncate font-medium text-gray-900 hover:text-primary">{{ $p->name }}</a>@else<p class="truncate font-medium text-gray-900">{{ $p->name }}</p>@endif
                                        <p class="font-mono text-xs text-gray-500">{{ $mask($p->primary_phone) }}@if ($p->cn) · {{ $p->cn }}@elseif ($p->external_no) · #{{ $p->external_no }}@endif</p>
                                    </div>
                                    <p class="shrink-0 text-right"><span class="font-semibold tabular-nums">৳{{ number_format((float) $p->amount) }}</span><br><x-badge :color="$badge[$p->status] ?? 'gray'">{{ __($statusLabels[$p->status] ?? $p->status) }}</x-badge></p>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($p->ordered_at)->format('d M Y') }}@if ($p->items) · {{ $p->items }}@endif</p>
                                @if ($p->address)<p class="truncate text-xs text-gray-400">{{ $p->address }}</p>@endif
                                <div class="mt-2 flex justify-end gap-2">
                                    <button type="button" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700"
                                        @click="$dispatch('edit-import-row', { action: @js(route('customers.history.order', [$import->id, $p->past_id])), title: @js(__('Edit order of :n', ['n' => $p->name])), fields: @js($orderFields($p)) })">{{ __('Edit') }}</button>
                                    <button type="button" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700"
                                        @click="picked = [{{ $p->past_id }}]; $dispatch('open-confirm', { id: 'orders-delete-confirm', form: 'orders-delete', label: @js($p->name) })">{{ __('Delete') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    {{-- Large screens: a table. --}}
                    <div class="-mx-4 mt-3 hidden overflow-x-auto px-4 lg:block">
                        <table class="w-full min-w-[60rem] text-sm">
                            <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                                <th class="w-8 py-2"></th><th class="py-2 pr-3 font-medium">{{ __('Customer') }}</th><th class="py-2 pr-3 font-medium">{{ __('Phone') }}</th><th class="py-2 pr-3 font-medium">{{ __('CN') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ __('Date') }}</th><th class="py-2 pr-3 font-medium">{{ __('Products') }}</th><th class="py-2 pr-3 font-medium">{{ __('Status') }}</th>
                                <th class="py-2 pr-3 text-right font-medium">{{ __('Amount') }}</th><th class="py-2"></th>
                            </tr></thead>
                            <tbody>
                                @foreach ($orders as $p)
                                    <tr class="border-b border-gray-100 align-top last:border-0" :class="picked.includes({{ $p->past_id }}) ? 'bg-green-50/40' : ''">
                                        <td class="py-2.5"><input type="checkbox" value="{{ $p->past_id }}" x-model.number="picked" class="h-4 w-4 rounded border-gray-300 text-primary"></td>
                                        <td class="py-2 pr-3">@if ($canJourney)<a href="{{ route('customers.show', $p->id) }}" class="font-medium text-gray-900 hover:text-primary">{{ $p->name }}</a>@else<span class="font-medium text-gray-900">{{ $p->name }}</span>@endif
                                            @if ($p->address)<span class="block max-w-xs truncate text-xs text-gray-400">{{ $p->address }}</span>@endif</td>
                                        <td class="whitespace-nowrap py-2 pr-3 font-mono text-gray-700">{{ $mask($p->primary_phone) }}</td>
                                        <td class="whitespace-nowrap py-2 pr-3 font-mono text-gray-700">{{ $p->cn ?? ($p->external_no ? '#'.$p->external_no : '-') }}</td>
                                        <td class="whitespace-nowrap py-2 pr-3 text-gray-700">{{ \Illuminate\Support\Carbon::parse($p->ordered_at)->format('d M Y') }}</td>
                                        <td class="max-w-xs py-2 pr-3 text-gray-600">{{ $p->items ?? '-' }}</td>
                                        <td class="py-2 pr-3"><x-badge :color="$badge[$p->status] ?? 'gray'">{{ __($statusLabels[$p->status] ?? $p->status) }}</x-badge></td>
                                        <td class="whitespace-nowrap py-2 pr-3 text-right font-semibold tabular-nums">৳{{ number_format((float) $p->amount) }}</td>
                                        <td class="whitespace-nowrap py-2 text-right">
                                            <button type="button" class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-primary hover:text-primary"
                                                @click="$dispatch('edit-import-row', { action: @js(route('customers.history.order', [$import->id, $p->past_id])), title: @js(__('Edit order of :n', ['n' => $p->name])), fields: @js($orderFields($p)) })">{{ __('Edit') }}</button>
                                            <button type="button" class="rounded-lg border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50"
                                                @click="picked = [{{ $p->past_id }}]; $dispatch('open-confirm', { id: 'orders-delete-confirm', form: 'orders-delete', label: @js($p->name) })">{{ __('Delete') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $orders->links() }}</div>
                    <x-confirm-modal id="orders-delete-confirm" :verb="__('Delete')" :message="__('These orders go from the customers\' history. A customer this import made who is left with nothing goes too.')" />
                @endif
            </div>

            {{-- Rows that did not come in: Edit (popup) brings one in; tick to delete several, or all. --}}
            @if ($problems)
                @php $why = ['bad_phone' => __('Phone not usable'), 'no_name' => __('No name'), 'bad_date' => __('Date not readable'), 'skipped' => __('Skipped by status')]; @endphp
                <details class="rounded-2xl border border-red-200 bg-white p-4" open x-data="{ picked: [], count: {{ count($problems) }}, get all() { return this.picked.length === this.count } }">
                    <summary class="cursor-pointer text-sm font-semibold text-red-800">{{ __('Rows that did not come in (:n)', ['n' => count($problems)]) }}</summary>
                    <p class="mt-2 text-xs text-gray-500">{{ __('Edit a row to fix it: Save brings it in. Delete takes it off this list; it stays out of IQS.') }}</p>
                    <form method="POST" action="{{ route('customers.history.drop-many', $import->id) }}" id="rows-delete">@csrf
                        <template x-for="k in picked" :key="k"><input type="hidden" name="rows[]" :value="k"></template>
                    </form>
                    <form method="POST" action="{{ route('customers.history.drop-many', $import->id) }}" id="rows-delete-all">@csrf <input type="hidden" name="all" value="1"></form>
                    <div class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-red-50/60 px-3 py-2 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary" :checked="all" @change="picked = $event.target.checked ? [...Array(count).keys()] : []"> {{ __('Tick all') }}</label>
                        <span class="text-gray-500" x-text="picked.length ? @js(__(':n ticked')).replace(':n', picked.length) : ''"></span>
                        <span class="ml-auto flex gap-2">
                            <button type="button" x-show="picked.length" x-cloak class="rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                                @click="$dispatch('open-confirm', { id: 'rows-delete-confirm', form: 'rows-delete', label: picked.length + ' ' + @js(__('rows')) })">{{ __('Delete ticked') }}</button>
                            <button type="button" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                                @click="$dispatch('open-confirm', { id: 'rows-delete-confirm', form: 'rows-delete-all', label: @js(__('all :n rows', ['n' => count($problems)])) })">{{ __('Delete all') }}</button>
                        </span>
                    </div>
                    <div class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($problems as $k => $pr)
                            @php
                                $prStatus = $guess->guessValue('status', (string) ($pr['status'] ?? ''));
                                $rowFields = ['name' => $pr['name'] ?? '', 'phone' => $pr['phone'] ?? '', 'cn' => $pr['cn'] ?? '', 'amount' => $pr['amount'] ?? '', 'address' => $pr['address'] ?? '',
                                    'items' => $pr['items'] ?? '', 'date' => $pr['date'] ?? '', 'status' => array_key_exists($prStatus, $editStatuses) ? $prStatus : 'delivered'];
                                $bad = ['bad_phone' => 'phone', 'no_name' => 'name', 'bad_date' => 'date', 'skipped' => 'status'][$pr['why']] ?? null;
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2 py-2.5" :class="picked.includes({{ $k }}) ? 'bg-red-50/40' : ''">
                                <label class="flex min-w-0 flex-1 items-center gap-2">
                                    <input type="checkbox" value="{{ $k }}" x-model.number="picked" class="h-4 w-4 shrink-0 rounded border-gray-300 text-primary">
                                    <span class="min-w-0"><span class="text-xs text-gray-400">{{ __('Row :n', ['n' => $pr['line']]) }}</span> <span class="font-medium text-gray-900">{{ $pr['name'] ?: '-' }}</span>
                                        <span class="font-mono text-xs text-gray-600">{{ $pr['phone'] ?: __('no phone') }}</span> <x-badge color="red">{{ $why[$pr['why']] ?? $pr['why'] }}</x-badge></span>
                                </label>
                                <span class="flex shrink-0 gap-2">
                                    <button type="button" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:border-primary hover:text-primary"
                                        @click="$dispatch('edit-import-row', { action: @js(route('customers.history.fix', [$import->id, $k])), title: @js(__('Fix row :n', ['n' => $pr['line']])), fields: @js($rowFields), bad: @js($bad), save: @js(__('Save and bring it in')) })">{{ __('Edit') }}</button>
                                    <form method="POST" action="{{ route('customers.history.drop', [$import->id, $k]) }}" id="drop-row-{{ $k }}">@csrf @method('DELETE')
                                        <button type="button" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50"
                                            @click="$dispatch('open-confirm', { id: 'rows-delete-confirm', form: 'drop-row-{{ $k }}', label: @js(__('Row :n', ['n' => $pr['line']])) })">{{ __('Delete') }}</button>
                                    </form>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <x-confirm-modal id="rows-delete-confirm" :verb="__('Delete')" :message="__('These rows are taken off the list and stay out of IQS.')" />
                </details>
            @endif

            {{-- The one popup for both lists: closes only with Cancel (typing is not lost to a stray tap). --}}
            <div x-data="{
                    action: '', title: '', save: '', fields: {}, errors: {}, bad: null, busy: false,
                    open(d) {
                        this.action = d.action; this.title = d.title; this.save = d.save || @js(__('Save'));
                        this.fields = { ...d.fields }; this.bad = d.bad || null; this.errors = {};
                        this.$dispatch('open-modal', 'import-row-edit');
                        // The status dropdown lives inside the popup (its own component): found by id, not by ref.
                        this.$nextTick(() => { const el = document.getElementById('import-row-status'); if (el) Alpine.$data(el).setValue(this.fields.status) });
                    },
                    async send() {
                        this.busy = true; this.errors = {};
                        try {
                            const r = await fetch(this.action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(this.fields) });
                            const j = await r.json().catch(() => ({}));
                            if (r.ok) { location.reload(); return }
                            this.errors = Object.fromEntries(Object.entries(j.errors || {}).map(([k, v]) => [k, v[0]]));
                            if (! Object.keys(this.errors).length) this.errors = { _: j.message || @js(__('Something went wrong.')) };
                        } catch (e) { this.errors = { _: @js(__('Something went wrong.')) } } finally { this.busy = false }
                    },
                }" @edit-import-row.window="open($event.detail)">
                <x-modal id="import-row-edit" width="max-w-2xl" persistent>
                    <p class="mb-3 text-sm font-semibold text-gray-900" x-text="title"></p>
                    <p x-show="errors._" x-cloak x-text="errors._" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['name' => __('Name'), 'phone' => __('Phone'), 'cn' => __('CN'), 'amount' => __('Amount'), 'date' => __('Date'), 'address' => __('Address'), 'items' => __('Products')] as $f => $label)
                            <label class="block @if (in_array($f, ['address', 'items'], true)) sm:col-span-2 @endif">
                                <span class="text-xs text-gray-500">{{ $label }}@if (in_array($f, ['name', 'phone'], true)) <span class="text-red-600">*</span>@endif</span>
                                <input type="text" x-model="fields.{{ $f }}" @if ($f === 'date') placeholder="2026-07-24" @elseif ($f === 'phone') placeholder="01XXXXXXXXX" inputmode="tel" @endif
                                    class="mt-0.5 w-full rounded-lg border px-3 py-2 text-base focus:border-primary focus:outline-none"
                                    :class="errors.{{ $f }} || bad === @js($f) ? 'border-red-400' : 'border-gray-300'">
                                <span x-show="errors.{{ $f }}" x-cloak x-text="errors.{{ $f }}" class="mt-0.5 block text-xs text-red-600"></span>
                            </label>
                        @endforeach
                        <label class="block">
                            <span class="text-xs text-gray-500">{{ __('Status') }}</span>
                            <div @select-change="fields.status = $event.detail">
                                <x-simple-select id="import-row-status" :options="$editStatuses" value="delivered" full-width class="mt-0.5" />
                            </div>
                        </label>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" @click="$dispatch('close-modal', 'import-row-edit')" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</button>
                        <button type="button" @click="send()" :disabled="busy" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark disabled:opacity-50"
                            x-text="busy ? @js(__('Saving...')) : save"></button>
                    </div>
                </x-modal>
            </div>
        @endif
    </div>
</x-layouts.app>
