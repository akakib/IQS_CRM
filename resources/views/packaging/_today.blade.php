{{-- Today: the A4 sheet's two boxes on screen (PackagingController::todaySheet, PackMarks). Each order of today's
     batches: label, items, COD, then Packed and Label on. A press opens a popup (the items with their size, or the label
     no to match the sticker); yes = ticked and locked. Only the owner or a manager takes a tick back: a packer asks. --}}
@php
    $statusMap = \App\Models\OrderStatus::map();
    $canTick = auth()->user()->can('packaging.create');
    $t = $sheet['totals'];
    $size = fn ($i) => \App\Support\Units::itemSize((object) ['unit' => $i->unit, 'variant' => (object) ['size_qty' => $i->size_qty, 'size_unit' => $i->size_unit]]);
    $at = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('g:i A') : '';
@endphp

<div class="mt-3" x-data="{ pop: null, open(p) { this.pop = p }, close() { this.pop = null } }" @keydown.escape.window="close()">
    <div class="mb-3 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl border border-gray-200 bg-white px-2 py-2.5"><p class="text-[11px] text-gray-500">{{ __('Orders today') }}</p><p class="text-lg font-semibold tabular-nums text-gray-900">{{ $t['orders'] }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white px-2 py-2.5"><p class="text-[11px] text-gray-500">{{ __('Packed') }}</p><p class="text-lg font-semibold tabular-nums text-gray-900">{{ $t['packed'] }}<span class="text-sm font-normal text-gray-400">/{{ $t['orders'] }}</span></p></div>
        <div class="rounded-xl border border-gray-200 bg-white px-2 py-2.5"><p class="text-[11px] text-gray-500">{{ __('Label on') }}</p><p class="text-lg font-semibold tabular-nums text-gray-900">{{ $t['label'] }}<span class="text-sm font-normal text-gray-400">/{{ $t['orders'] }}</span></p></div>
    </div>

    @forelse ($sheet['groups'] as $g)
        @php
            $b = $g['batch'];
            $gp = $g['orders']->filter(fn ($o) => $o->packed_at && ! ($sheet['repack'])($o))->count();
            $gl = $g['orders']->filter(fn ($o) => $sheet['labels']->get($o->id)?->applied_at)->count();
        @endphp
        <div class="mb-4 overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-4 py-2.5">
                <p class="text-sm">@if ($b)<a href="{{ route('packaging.batch-list', $b->id) }}" class="font-mono font-semibold text-gray-900 hover:text-primary">{{ $b->batch_no }}</a> <span class="text-gray-500">· {{ $at($b->released_at) }} · {{ $b->by ?? '-' }}</span>@else<span class="font-semibold text-gray-900">{{ __('Packed today, not in a batch') }}</span>@endif</p>
                <p class="text-xs text-gray-600">{{ __('Packed :a/:n · Label :b/:n', ['a' => $gp, 'b' => $gl, 'n' => $g['orders']->count()]) }}</p>
            </div>
            <div class="divide-y divide-gray-100">
                @foreach ($g['orders'] as $k => $o)
                    @php
                        $label = $sheet['labels'][$o->id] ?? null;
                        $items = $sheet['items'][$o->id] ?? collect();
                        $repack = ($sheet['repack'])($o);
                        $isPacked = $o->packed_at && ! $repack;
                        $isOn = (bool) ($label?->applied_at);
                        $key = $statusMap[$o->status_id]['key'] ?? '';
                        $canPack = $canTick && ! $isPacked && ($key === 'ready_for_packaging' || $repack) && $label && (int) $o->label_version === (int) $o->current_version;
                        $asks = $sheet['asks'][$o->id] ?? collect();
                        $popItems = $items->map(fn ($i) => ['name' => $i->name_snapshot, 'size' => $size($i), 'qty' => \App\Support\Units::qty($i->qty, $i->unit), 'image' => $i->image_url])->values();
                    @endphp
                    <div id="o{{ $o->id }}" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 gap-3">
                            <span class="w-5 shrink-0 pt-0.5 text-xs tabular-nums text-gray-400">{{ $k + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm font-semibold text-gray-900">{{ $label->barcode ?? $o->order_no }}
                                    <span class="ml-1 font-sans text-xs font-normal text-gray-500">{{ collect([$o->ship_thana, \App\Support\BdDistricts::name($o->ship_district)])->filter()->unique()->join(', ') }} · COD ৳{{ number_format((float) $o->cod_amount) }}</span></p>
                                @if ($repack)<p class="text-xs font-semibold text-red-700">{{ __('Changed after packing: repack with the new label') }}</p>@endif
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($items as $i)
                                        <span class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-gray-200 py-0.5 pl-0.5 pr-2 text-xs text-gray-700"><x-product-thumb :src="$i->image_url" size="sm" /><span class="min-w-0 truncate">{{ $i->name_snapshot }}{{ ($s = $size($i)) ? ' '.$s : '' }}</span><b class="shrink-0">{{ \App\Support\Units::qty($i->qty, $i->unit) }}</b></span>
                                    @endforeach
                                </div>
                                @foreach ($asks as $a)
                                    <div class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                        <span>{{ __(':n asks to undo :w: ":r"', ['n' => $a->by ?? '-', 'w' => $a->mark === 'packed' ? __('Packed') : __('Label on'), 'r' => $a->reason]) }}</span>
                                        @if ($canUndo)
                                            <form method="POST" action="{{ route('packaging.undo-decide', $a->id) }}" class="flex gap-1.5">@csrf
                                                <button name="decision" value="approve" class="h-7 rounded-lg bg-primary px-2.5 font-semibold text-white">{{ __('Approve') }}</button>
                                                <button name="decision" value="refuse" class="h-7 rounded-lg border border-amber-400 bg-white px-2.5 font-semibold">{{ __('Refuse') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="grid shrink-0 grid-cols-2 gap-2 sm:w-64">
                            {{-- Packed --}}
                            <div class="text-center">
                                @if ($isPacked)
                                    <div class="flex min-h-11 flex-col items-center justify-center rounded-lg border border-green-300 bg-green-50 px-2 py-1 text-xs text-green-800"><b>✓ {{ __('Packed') }}</b><span>{{ $at($o->packed_at) }} · {{ $o->packer ?? '-' }}</span></div>
                                    @if ($canTick && $asks->where('mark', 'packed')->isEmpty())<button type="button" @click="open({ kind: 'undo', mark: 'packed', no: @js($o->order_no), url: @js($canUndo ? route('packaging.undo-now', $o->id) : route('packaging.undo-ask', $o->id)), admin: @js($canUndo) })" class="mt-1 text-[11px] text-gray-500 underline">{{ $canUndo ? __('Take back') : __('Ask to undo') }}</button>@endif
                                @elseif ($canPack)
                                    <button type="button" @click="open({ kind: 'packed', no: @js($o->order_no), items: @js($popItems), url: @js(route('packaging.mark', [$o->id, 'packed'])) })" class="lockable min-h-11 w-full rounded-lg border-2 border-gray-300 bg-white px-2 text-sm font-semibold text-gray-800 hover:border-primary hover:text-primary">{{ __('Packed') }}</button>
                                @else
                                    <div class="flex min-h-11 items-center justify-center rounded-lg border border-dashed border-gray-300 px-2 text-xs text-gray-400">{{ $key === 'ready_for_packaging' || $repack ? __('Print the label first') : __($statusMap[$o->status_id]['name'] ?? '-') }}</div>
                                @endif
                            </div>
                            {{-- Label on --}}
                            <div class="text-center">
                                @if ($isOn)
                                    <div class="flex min-h-11 flex-col items-center justify-center rounded-lg border border-green-300 bg-green-50 px-2 py-1 text-xs text-green-800"><b>✓ {{ __('Label on') }}</b><span>{{ $at($label->applied_at) }} · {{ $label->by ?? '-' }}</span></div>
                                    @if ($canTick && $asks->where('mark', 'label')->isEmpty())<button type="button" @click="open({ kind: 'undo', mark: 'label', no: @js($o->order_no), url: @js($canUndo ? route('packaging.undo-now', $o->id) : route('packaging.undo-ask', $o->id)), admin: @js($canUndo) })" class="mt-1 text-[11px] text-gray-500 underline">{{ $canUndo ? __('Take back') : __('Ask to undo') }}</button>@endif
                                @elseif ($canTick && $isPacked && $label)
                                    <button type="button" @click="open({ kind: 'label', no: @js($o->order_no), label: @js($label->barcode), url: @js(route('packaging.mark', [$o->id, 'label'])) })" class="lockable min-h-11 w-full rounded-lg border-2 border-gray-300 bg-white px-2 text-sm font-semibold text-gray-800 hover:border-primary hover:text-primary">{{ __('Label on') }}</button>
                                @else
                                    <div class="flex min-h-11 items-center justify-center rounded-lg border border-dashed border-gray-300 px-2 text-xs text-gray-400">{{ __('After Packed') }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No batch printed today yet. Print next batch to start.') }}</div>
    @endforelse

    {{-- The popup: Packed (the items to see), Label on (the label no to match), or undo (a reason). --}}
    <div x-show="pop" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center bg-black/40 sm:items-center sm:p-4">
        <div class="absolute inset-0" @click="close()"></div>
        <form method="POST" :action="pop?.url" class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
            @csrf
            <template x-if="pop && pop.kind === 'packed'">
                <div>
                    <p class="text-base font-semibold text-gray-900" x-text="pop.no + ': ' + @js(__('is all of this in the box?'))"></p>
                    <div class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200">
                        <template x-for="(i, n) in pop.items" :key="n">
                            <div class="flex items-center gap-3 px-3 py-2 text-sm">
                                <x-product-thumb x-src="i.image" />
                                <span class="min-w-0 flex-1 text-gray-800"><span x-text="i.name"></span> <span class="text-gray-500" x-show="i.size" x-text="i.size"></span></span>
                                <b class="shrink-0 tabular-nums" x-text="i.qty"></b>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            <template x-if="pop && pop.kind === 'label'">
                <div class="text-center">
                    <p class="text-base font-semibold text-gray-900" x-text="@js(__('Is this label on the box of')) + ' ' + pop.no + '?'"></p>
                    <p class="mt-3 font-mono text-3xl font-bold tracking-wider text-gray-900" x-text="pop.label"></p>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Match it with the sticker on the box.') }}</p>
                </div>
            </template>
            <template x-if="pop && pop.kind === 'undo'">
                <div>
                    <input type="hidden" name="mark" :value="pop.mark">
                    <p class="text-base font-semibold text-gray-900" x-text="(pop.admin ? @js(__('Take back')) : @js(__('Ask to undo'))) + ' ' + (pop.mark === 'packed' ? @js(__('Packed')) : @js(__('Label on'))) + ': ' + pop.no"></p>
                    <p class="mt-1 text-xs text-gray-500" x-show="!pop.admin">{{ __('The owner or a manager answers. The tick stays until then.') }}</p>
                    <p class="mt-1 text-xs text-gray-500" x-show="pop.admin && pop.mark === 'packed'">{{ __('The order goes back to packing and its items back on the shelf.') }}</p>
                    <textarea name="reason" required minlength="3" maxlength="300" rows="2" class="mt-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none" placeholder="{{ __('What went wrong?') }}"></textarea>
                </div>
            </template>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="close()" class="h-8 rounded-lg border border-gray-300 px-4 text-sm text-gray-600 hover:bg-gray-50">{{ __('Cancel') }}</button>
                <button class="lockable h-8 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-dark" x-text="pop && pop.kind === 'undo' ? (pop.admin ? @js(__('Take back')) : @js(__('Send'))) : @js(__('Yes, confirm'))"></button>
            </div>
        </form>
    </div>
</div>
