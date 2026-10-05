@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $types = ['no_answer' => __('Customer not answering'), 'partial' => __('Partial delivery'), 'cancel' => __('Wants to cancel'), 'exchange' => __('Exchange'), 'address' => __('Address problem'), 'hold' => __('Deliver later'), 'other' => __('Other')];
@endphp

<x-layouts.app :heading="__('Rider hotline')">
    <form method="GET" action="{{ route('hotline.index') }}" class="mb-4 flex gap-2">
        <input name="q" value="{{ $q }}" autofocus placeholder="{{ __('CN number, customer phone, order no or name') }}" class="{{ $input }} text-base">
        <x-button>{{ __('Find') }}</x-button>
    </form>

    @if ($q !== '' && $results->isEmpty())
        <x-empty-state :message="__('Nothing found for that.')" />
    @endif

    @if ($results->count() > 1)
        <div class="mb-4 space-y-2">
            @foreach ($results as $r)
                <a href="{{ route('hotline.index', ['q' => $q, 'order' => $r->id]) }}" class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-3 text-sm hover:border-primary">
                    <span><b class="font-mono">{{ $r->order_no }}</b> · {{ $r->ship_name }}</span>
                    <x-order-status :order="$r" :statuses="$statuses" />
                </a>
            @endforeach
        </div>
    @endif

    @if ($detail)
        @php($o = $detail['order'])
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                <x-card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <a href="{{ route('orders.show', $o) }}" class="font-mono text-lg font-semibold hover:underline">{{ $o->order_no }}</a>
                            <x-order-status :order="$o" :statuses="$statuses" />
                            <p class="mt-1 text-sm text-gray-600">{{ __('CN') }} {{ $detail['shipment']->consignment_id ?? '-' }} · {{ $detail['shipment']->courier_status ?? '' }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-500">{{ __('COD') }}</p>
                            <p class="text-2xl font-bold tabular-nums">৳{{ number_format((float) $o->cod_amount) }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-sm"><b>{{ $o->ship_name }}</b> · <a href="tel:{{ $o->ship_phone }}" class="font-mono text-primary">{{ $o->ship_phone }}</a>{{ $o->ship_alt_phone ? ' / '.$o->ship_alt_phone : '' }}</p>
                    <p class="text-sm text-gray-600">{{ collect([$o->ship_address, $o->ship_thana, $o->ship_district])->filter()->join(', ') }}</p>
                    <p class="mt-2 text-sm text-gray-700">{{ $o->items->map(fn ($i) => $i->name_snapshot.' ×'.rtrim(rtrim($i->qty, '0'), '.'))->join(', ') }}</p>
                    <p class="mt-2 text-sm">{{ __('Assigned to') }}: <b>{{ $o->moderator?->name ?? __('nobody') }}</b></p>
                    @foreach ($detail['issues'] as $i)
                        <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ __('Open issue: :t, due :d', ['t' => $types[$i->issue_type] ?? $i->issue_type, 'd' => \Illuminate\Support\Carbon::parse($i->sla_due_at)->diffForHumans()]) }}</p>
                    @endforeach
                </x-card>

                <x-card :title="__('Timeline')">
                    <ol class="space-y-2 text-sm">
                        @foreach ($detail['notes'] as $n)
                            <li><p class="text-gray-800">{{ $n->body }}</p><p class="text-xs text-gray-400">{{ $n->user ?? __('System') }} · {{ \Illuminate\Support\Carbon::parse($n->created_at)->format('d M, g:i A') }}</p></li>
                        @endforeach
                    </ol>
                </x-card>
            </div>

            <div class="space-y-4">
                <x-card :title="__('Small issue: solved here')">
                    <form method="POST" action="{{ route('hotline.solved', $o) }}" class="space-y-2">
                        @csrf
                        <input name="rider_phone" inputmode="tel" placeholder="{{ __('Rider phone (optional)') }}" class="{{ $input }}">
                        <textarea name="note" rows="2" required maxlength="500" placeholder="{{ __('What was done, e.g. called the customer, she will receive in 10 minutes') }}" class="{{ $input }}"></textarea>
                        <x-button variant="secondary" class="w-full">{{ __('Save') }}</x-button>
                    </form>
                </x-card>

                @can('hotline.create')
                    <x-card :title="__('Bigger issue: send to the assigned moderator')" x-data="{ type: 'partial' }">
                        <form method="POST" action="{{ route('hotline.issue', $o) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="issue_type" :value="type">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($types as $key => $label)
                                    <button type="button" @click="type = @js($key)" class="rounded-full border px-2.5 py-1 text-xs" :class="type === @js($key) ? 'border-red-600 bg-red-600 text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                                @endforeach
                            </div>
                            <input name="rider_phone" inputmode="tel" placeholder="{{ __('Rider phone') }}" class="{{ $input }}">
                            <textarea name="note" rows="2" maxlength="500" placeholder="{{ __('Details') }}" class="{{ $input }}"></textarea>
                            <x-button variant="danger" class="w-full">{{ __('Send with timer') }}</x-button>
                        </form>
                    </x-card>
                @endcan
            </div>
        </div>
    @endif
</x-layouts.app>
