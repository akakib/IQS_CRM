@php
    use App\Services\Customers\HistoryImport;
    $fieldOptions = ['' => __('Ignore')] + array_map(fn ($l) => __($l), HistoryImport::FIELDS);
    $statusOptions = array_map(fn ($l) => __($l), HistoryImport::STATUSES);
    $headers = array_column($columns, 'header');
    $map = array_map(fn ($h) => $mapping[$h] ?? '', $headers);
    $countLabels = ['orders' => __('Orders to import'), 'known' => __('Known customers'), 'new' => __('New customers'), 'in_iqs' => __('Already in IQS'),
        'already' => __('Imported before'), 'skipped' => __('Skipped by status'), 'bad_phone' => __('Bad phone'), 'no_name' => __('No name'), 'bad_date' => __('Bad date')];
@endphp

<x-layouts.app :heading="__('Map the columns')">
    <div class="mx-auto max-w-3xl space-y-4"
        x-data="{
            headers: @js($headers), map: @js($map), values: @js(['status' => (object) ($values['status'] ?? []), 'medium' => (object) ($values['medium'] ?? [])]),
            defaultMedium: 'other', source: @js($import->source ?? ''), counts: null, sample: [], error: '', busy: false,
            has(f) { return this.map.includes(f) },
            body() { const m = {}; this.headers.forEach((h, i) => m[h] = this.map[i]); return { mapping: m, values: this.values, default_medium: this.defaultMedium, source: this.src() } },
            src() { return (document.getElementById('history-source')?.value || '').trim() },
            async send(url) {
                this.error = ''; this.busy = true;
                try {
                    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(this.body()) });
                    const j = await r.json();
                    if (! r.ok) { this.error = j.message || @js(__('Something went wrong.')); return null }
                    return j;
                } catch (e) { this.error = @js(__('Something went wrong.')); return null } finally { this.busy = false }
            },
            async check() { if (! this.has('phone') || ! this.has('name')) { this.error = @js(__('Pick the Phone and the Name columns.')); return } const j = await this.send(@js(route('customers.history.preview', $import->id))); if (j) { this.counts = j.counts; this.sample = j.sample } },
            async run() { const j = await this.send(@js(route('customers.history.run', $import->id))); if (j) window.location = j.url },
        }"
        x-init="$refs.rf.submit = () => run()">
        <a href="{{ route('customers.history') }}" class="inline-block text-sm text-primary hover:underline">{{ __('Back to imports') }}</a>

        <div class="rounded-2xl border border-gray-200 bg-white p-4">
            <p class="truncate text-sm font-semibold text-gray-900">{{ $import->file_name }}</p>
            <p class="text-xs text-gray-500">{{ trans_choice(':count row|:count rows', $import->row_count, ['count' => $import->row_count]) }}
                · @if ($fromTemplate){{ __('Matched the saved template :t. Change anything below; the template updates.', ['t' => $import->source]) }}@else{{ __('New file shape. IQS guessed the fields marked auto; check them.') }}@endif</p>
            <label class="mt-3 block text-xs font-medium text-gray-700">{{ __('Source name (shown on the journey; the file name if left empty)') }}</label>
            <input type="text" id="history-source" value="{{ $import->source ?? '' }}" autocomplete="off" @input="counts = null" maxlength="80" placeholder="{{ __('WooCommerce, Pathao...') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-base focus:border-primary focus:outline-none">
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-4">
            <p class="text-sm font-semibold text-gray-900">{{ __('Your columns and what they are') }}</p>
            <p class="mb-2 text-xs text-gray-500">{{ __('Phone and Name are a must. CN and Order number only if the file has them. Name and Address may take two columns (first and last name). Rows with the same order number or CN become one order.') }}</p>
            @foreach ($columns as $i => $c)
                <div class="grid grid-cols-1 items-center gap-2 border-t border-gray-100 py-2.5 sm:grid-cols-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-800">{{ $c['header'] }}
                            @if (! $fromTemplate && $map[$i] !== '')<span class="ml-1 rounded-full bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-700">{{ __('auto') }}</span>@endif</p>
                        <p class="truncate text-xs text-gray-400">{{ $c['sample'] !== '' ? __('e.g. :v', ['v' => $c['sample']]) : __('empty') }}</p>
                    </div>
                    <div @select-change="map[{{ $i }}] = $event.detail; counts = null">
                        <x-simple-select :options="$fieldOptions" :value="$map[$i]" full-width />
                    </div>
                </div>
            @endforeach
        </div>

        {{-- What the file's own words mean. --}}
        @foreach (['status' => [__('Status words in this file'), $statusOptions], 'medium' => [__('Medium words in this file'), $channels]] as $kind => [$title, $opts])
            @foreach ($columns as $i => $c)
                <div x-show="map[{{ $i }}] === @js($kind)" x-cloak class="rounded-2xl border border-gray-200 bg-white p-4">
                    <p class="text-sm font-semibold text-gray-900">{{ $title }} <span class="font-normal text-gray-500">({{ $c['header'] }})</span></p>
                    @if ($c['values'] === null)
                        <p class="mt-1 text-xs text-gray-500">{{ __('Too many different words to list; each one is guessed.') }}</p>
                    @else
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($c['values'] as $w)
                                <div class="flex items-center justify-between gap-2 rounded-xl bg-gray-50 px-3 py-2" @select-change="values[@js($kind)][@js($w)] = $event.detail; counts = null">
                                    <span class="min-w-0 truncate text-sm text-gray-800">{{ $w }}</span>
                                    <x-simple-select size="sm" :options="$opts" :value="$values[$kind][$w] ?? null" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        @endforeach

        <div x-show="! has('medium')" class="rounded-2xl border border-gray-200 bg-white p-4" @select-change="defaultMedium = $event.detail; counts = null">
            <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('No medium column: where did these orders come from?') }}</p>
            <x-simple-select :options="$channels" value="other" full-width />
        </div>

        <div class="sticky bottom-3 z-10 rounded-2xl border border-gray-200 bg-white p-4 shadow-lg">
            <p x-show="error" x-text="error" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>
            <template x-if="counts">
                <div class="mb-3">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($countLabels as $k => $label)
                            <div class="rounded-xl bg-gray-50 p-2.5"><p class="text-[11px] text-gray-500">{{ $label }}</p>
                                <p class="text-base font-semibold tabular-nums" :class="@js(in_array($k, ['bad_phone', 'no_name', 'bad_date'], true)) && counts.{{ $k }} ? 'text-red-700' : 'text-gray-900'" x-text="counts.{{ $k }}.toLocaleString()"></p></div>
                        @endforeach
                    </div>
                    <div class="mt-2 space-y-1">
                        <template x-for="o in sample" :key="o.phone + o.ordered_at">
                            <p class="truncate text-xs text-gray-600"><span class="font-medium text-gray-800" x-text="o.name"></span> · <span x-text="o.ordered_at.slice(0, 10)"></span> · <span x-text="'৳' + Math.round(o.amount).toLocaleString()"></span> · <span x-text="o.status"></span> · <span x-text="o.medium || '-'"></span> · <span x-text="o.items || '-'"></span></p>
                        </template>
                    </div>
                </div>
            </template>
            <div class="flex gap-2">
                <button type="button" @click="check()" :disabled="busy" class="flex-1 rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50">{{ __('Check') }}</button>
                <button type="button" @click="$dispatch('open-confirm', { id: 'history-run', form: 'history-run-form', label: counts ? counts.orders + ' ' + @js(__('orders')) : '' })" :disabled="busy || ! counts || ! counts.orders"
                    class="flex-[2] rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white hover:bg-primary-dark disabled:opacity-50"
                    x-text="counts ? @js(__('Import :n orders')).replace(':n', counts.orders.toLocaleString()) : @js(__('Check first'))"></button>
            </div>
            <x-confirm-modal id="history-run" :verb="__('Import')" :danger="false" :message="__('These orders go into the customers\' journeys and new numbers become customers. This cannot be undone from here.')" />
            {{-- The confirm dialog submits a form; this one runs the import instead. --}}
            <form id="history-run-form" x-ref="rf" class="hidden"></form>
        </div>
    </div>
</x-layouts.app>
