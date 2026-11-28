{{-- A packer's Handover (HandoverController, simple): Ready and Given, one big Scan button.
     No handover open: Scan asks the rider (a known one in one tap, or a new name and phone), then the camera opens.
     Open: each scan moves a parcel from Ready to Given (green or red, a sound, a buzz). Typing the order no or its last digits works
     for a torn barcode; ticking by hand is for a manager. Finish: the summary, then the slip for the rider to sign (thermal printer,
     a 76 mm PDF, or A4). The parcels not given wait for the next pickup. --}}
@php
    $open = $session && ! $session->closed_at;
    $closed = $session && $session->closed_at;
    $area = fn ($r) => $r->ship_thana ? $r->ship_thana.($r->ship_district ? ', '.$r->ship_district : '') : ($r->ship_district ?: '-');
    $card = fn ($r) => ['no' => $r->order_no, 'cn' => (string) $r->consignment_id, 'name' => (string) $r->ship_name, 'area' => $area($r), 'cod' => '৳'.number_format((float) $r->cod_amount)];
@endphp
<x-layouts.app :heading="__('Handover')">
    <div x-data="{
            tab: 'ready', last: null, pick: false, name: '', phone: '', riders: @js($riders),
            ready: @js($missing->map($card)->values()),
            given: @js($handed->map($card)->values()),
            async handle(code) {
                try {
                    const r = await fetch(@js($open ? route('handover.scan', $session->id) : ''), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ code, via: window.iqsScanVia || null }) });
                    this.last = await r.json();
                } catch (e) { this.last = { ok: false, level: 'red', message: @js(__('Connection problem. Scan again.')) }; }
                if (this.last.ok) {
                    const no = this.last.order?.order_no, i = this.ready.findIndex(p => p.no === no);
                    this.given.unshift(i >= 0 ? this.ready.splice(i, 1)[0] : { no, cn: '', name: '', area: '', cod: '' });
                }
                $dispatch('scan-result', { ok: this.last.ok, message: this.last.message, level: this.last.level });
            },
        }" x-init="@if ($open && request('scan')) $nextTick(() => document.querySelector('#hand-scan button[aria-label]')?.click()) @endif">

        @if ($closed)
            {{-- Finished: the summary and the slip. --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4 text-center">
                <p class="text-sm text-gray-500">{{ \Illuminate\Support\Carbon::parse($session->closed_at)->format('d M Y, g:i A') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $session->rider_name ?? __('Rider') }} <span class="font-normal text-gray-500">{{ $session->rider_phone }}</span></p>
                <p class="mt-3 text-4xl font-bold tabular-nums text-green-700">{{ $handed->count() }}</p>
                <p class="text-base font-medium text-green-800">{{ trans_choice('parcel given|parcels given', $handed->count()) }}</p>
                @if ($missing->isNotEmpty())
                    <p class="mt-2 inline-block rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">{{ trans_choice(':count stays for the next pickup|:count stay for the next pickup', $missing->count()) }}</p>
                @endif
            </div>

            @php
                $slip = [
                    'rider' => trim(($session->rider_name ?? __('Rider')).' '.($session->rider_phone ?? '')),
                    'head' => [\Illuminate\Support\Carbon::parse($session->closed_at)->format('d M Y, g:i A')],
                    'parcels' => $handed->map(fn ($r) => ['no' => $r->order_no, 'cn' => (string) $r->consignment_id, 'area' => $area($r), 'cod' => '৳'.number_format((float) $r->cod_amount)])->values(),
                    'count' => trans_choice(':count parcel|:count parcels', $handed->count()),
                    'total' => 'COD ৳'.number_format((float) $handed->sum('cod_amount')),
                    'by' => $packers ?: '-',
                ];
            @endphp
            @if ($handed->isNotEmpty())
                <div class="mt-4 space-y-2" x-data="{ msg: '', bad: false, busy: false, canPrint: IqsPrint.canPrint,
                        async print() {
                            this.busy = true; this.bad = false; this.msg = @js(__('Printing…'));
                            try { await IqsPrint.printReceipt(@js($slip), !IqsPrint.status().receipt, () => {}, IqsPrint.slip); this.msg = @js(__('Printed. The rider signs it.')); }
                            catch (e) { this.bad = true; this.msg = e.name === 'NotFoundError' ? @js(__('No printer chosen. Is it on (and its cable in)?')) : (e.message || String(e)); }
                            this.busy = false;
                        } }">
                    <button type="button" x-show="canPrint" :disabled="busy" @click="print()" class="flex h-14 w-full items-center justify-center rounded-xl bg-primary text-base font-semibold text-white disabled:opacity-50">{{ __('Print slip') }}</button>
                    <button type="button" @click="IqsPrint.slipPdf(@js($slip), @js('handover-'.$session->id.'.pdf'))" :class="canPrint ? 'border-2 border-primary bg-white text-primary' : 'bg-primary text-white'" class="flex h-12 w-full items-center justify-center rounded-xl text-base font-semibold">{{ __('Slip PDF') }}</button>
                    <a href="{{ route('handover.print', $session->id) }}" target="_blank" class="flex h-12 w-full items-center justify-center rounded-xl border border-gray-300 bg-white text-base font-medium text-gray-700">{{ __('A4 print') }}</a>
                    <p x-show="msg" x-text="msg" class="text-center text-sm font-medium" :class="bad ? 'text-red-700' : 'text-green-800'"></p>
                </div>
            @endif
            <a href="{{ route('handover.index') }}" class="mt-4 flex h-12 w-full items-center justify-center rounded-xl border border-gray-300 bg-white text-base font-medium text-gray-700">{{ __('New handover') }}</a>
        @else
            {{-- Who takes them, and the count. --}}
            @if ($open)
                <div class="mb-3 rounded-2xl border border-gray-200 bg-white p-3">
                    <p class="text-sm text-gray-500">{{ __('Rider') }}</p>
                    <div class="flex items-center justify-between gap-2">
                        <p class="min-w-0 text-lg font-semibold text-gray-900">{{ $session->rider_name ?? '-' }} <span class="font-normal text-gray-500">{{ $session->rider_phone }}</span></p>
                        {{-- The wrong rider: another one, while nothing has been given yet. --}}
                        <button type="button" x-show="!given.length" @click="pick = true" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700">{{ __('Change') }}</button>
                    </div>
                </div>
            @endif

            <div class="mb-3 grid grid-cols-2 gap-1 rounded-2xl bg-gray-200/70 p-1">
                <button type="button" @click="tab = 'ready'" class="rounded-xl py-3 text-center text-sm sm:text-base" :class="tab === 'ready' ? 'bg-white font-semibold text-blue-700 shadow-sm' : 'text-gray-600'">{{ __('Ready') }}<br><b class="text-xl tabular-nums" x-text="ready.length"></b></button>
                <button type="button" @click="tab = 'given'" class="rounded-xl py-3 text-center text-sm sm:text-base" :class="tab === 'given' ? 'bg-white font-semibold text-green-700 shadow-sm' : 'text-gray-600'">{{ __('Given') }}<br><b class="text-xl tabular-nums" x-text="given.length"></b></button>
            </div>

            @if ($open)
                <div id="hand-scan" class="mb-3"><x-scan-input quiet :placeholder="__('Scan, or type the order no')" @scan="handle($event.detail)" /></div>
                {{-- The last scan, big: green given, red keep it aside (the reason in words). --}}
                <div x-show="last" x-cloak class="mb-3 rounded-xl border-2 p-3"
                    :class="{ 'border-green-600 bg-green-50 text-green-900': last?.ok, 'border-amber-500 bg-amber-50 text-amber-950': !last?.ok && ['warn', 'cod', 'orange'].includes(last?.level), 'border-red-600 bg-red-50 text-red-900': !last?.ok && !['warn', 'cod', 'orange'].includes(last?.level) }">
                    <p class="text-lg font-bold" x-text="last?.message"></p>
                    <p class="font-mono text-sm" x-text="last?.order?.order_no"></p>
                </div>
            @else
                {{-- One from an earlier day still open: finish it first (owner 2026-11-29), then a new one starts. --}}
                @if ($stale)
                    <div class="mb-3 rounded-2xl border-2 border-amber-400 bg-amber-50 p-3">
                        <p class="text-base font-semibold text-amber-900">{{ __('Not finished: :d · :r · :n given', ['d' => \Illuminate\Support\Carbon::parse($stale->started_at)->format('d M'), 'r' => $stale->rider_name ?? __('Rider'), 'n' => $stale->given]) }}</p>
                        <form method="POST" action="{{ route('handover.close', $stale->id) }}" class="mt-2">@csrf
                            <button class="h-12 w-full rounded-xl bg-amber-500 text-base font-semibold text-white">{{ $stale->given ? __('Finish it') : __('Close, nothing given') }}</button>
                        </form>
                    </div>
                @endif
                {{-- Scan: first the rider. --}}
                <div>
                    <button type="button" @click="pick = true" :disabled="!ready.length || @js((bool) $stale)" class="mb-3 flex h-14 w-full items-center justify-center rounded-xl bg-primary text-lg font-semibold text-white disabled:opacity-40">
                        <svg class="mr-2 h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 8V5a1 1 0 011-1h3M16 4h3a1 1 0 011 1v3M20 16v3a1 1 0 01-1 1h-3M8 20H5a1 1 0 01-1-1v-3M8 9v6M11 9v6M14 9v6M17 9v6"/></svg>{{ __('Scan') }}</button>
                    @if ($last)
                        <a href="{{ route('handover.show', $last->id) }}" class="-mt-1 mb-3 block text-center text-sm text-blue-700 underline">{{ __('Last handover: :r, :t (print again)', ['r' => $last->rider_name ?? __('Rider'), 't' => \Illuminate\Support\Carbon::parse($last->closed_at)->format('g:i A')]) }}</a>
                    @endif
                </div>
            @endif

            {{-- The parcels: enough to find the box on the shelf. --}}
            <div class="space-y-2">
                <template x-for="p in (tab === 'ready' ? ready : given)" :key="p.no">
                    <div class="rounded-xl border bg-white p-3" :class="tab === 'given' ? 'border-green-200' : 'border-gray-200'">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="font-mono text-base font-bold text-gray-900"><span x-text="p.no"></span> <span x-show="p.cn" class="font-sans text-sm font-medium text-gray-500" x-text="'CN ' + p.cn"></span></p>
                            <p class="shrink-0 text-sm font-semibold tabular-nums text-gray-700" x-text="p.cod"></p>
                        </div>
                        <p class="truncate text-sm text-gray-600"><span x-text="p.name"></span><span x-show="p.name && p.area"> · </span><span x-text="p.area"></span></p>
                    </div>
                </template>
                <p x-show="!(tab === 'ready' ? ready : given).length" class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500"
                    x-text="tab === 'ready' ? @js(__('Nothing is ready to hand over.')) : @js(__('Nothing given yet.'))"></p>
            </div>

            @if ($open)
                {{-- Finish: the question in Bangla, big (owner 2026-11-29, like the packer's Done popup). --}}
                <div x-data="{ ask: false, bn: n => Number(n).toLocaleString('bn-BD') }">
                    <button type="button" :disabled="!given.length" @click="ask = true" class="mt-4 h-14 w-full rounded-xl bg-green-600 text-lg font-semibold text-white disabled:opacity-40">{{ __('Finish') }}</button>
                    <div x-show="ask" x-cloak class="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 px-4" @click.self="ask = false" @keydown.escape.window="ask = false">
                        <form method="POST" action="{{ route('handover.close', $session->id) }}" class="w-full max-w-sm rounded-2xl bg-white p-5 text-center shadow-xl">@csrf
                            <p class="text-2xl font-bold text-gray-900">হ্যান্ডওভার শেষ</p>
                            <p class="mt-4 text-xl leading-relaxed text-gray-800"><b class="text-green-700" x-text="bn(given.length) + 'টা'"></b> পার্সেল রাইডার <b>{{ $session->rider_name ?? '' }}</b>-কে দেওয়া হয়েছে।</p>
                            <p x-show="ready.length" class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xl leading-relaxed text-amber-900"><b x-text="bn(ready.length) + 'টা'"></b> পার্সেল পরের পিকআপে যাবে।</p>
                            <div class="mt-6 grid grid-cols-2 gap-3">
                                <button type="button" @click="ask = false" class="h-14 rounded-xl border-2 border-gray-300 text-xl font-semibold text-gray-700">না</button>
                                <button class="h-14 rounded-xl bg-green-600 text-xl font-semibold text-white">হ্যাঁ, শেষ</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endif

        {{-- Which rider: to start, or (nothing given yet) to change. --}}
        @if (! $closed)
                    <div x-show="pick" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="pick = false" @keydown.escape.window="pick = false">
                        <form method="POST" action="{{ $open ? route('handover.rider', $session->id) : route('handover.start') }}" x-ref="start" class="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:rounded-2xl">
                            @csrf
                            <input type="hidden" name="rider_name" :value="name"><input type="hidden" name="rider_phone" :value="phone">
                            <p class="mb-3 text-lg font-semibold text-gray-900">{{ __('Which rider?') }}</p>
                            <div class="space-y-2">
                                <template x-for="r in riders" :key="r.name">
                                    <button type="button" @click="name = r.name; phone = r.phone; $nextTick(() => $refs.start.submit())" class="flex h-14 w-full items-center justify-between rounded-xl border-2 border-gray-200 px-4 text-left hover:border-primary">
                                        <span class="truncate text-base font-semibold text-gray-900" x-text="r.name"></span><span class="shrink-0 text-sm tabular-nums text-gray-500" x-text="r.phone"></span>
                                    </button>
                                </template>
                            </div>
                            <p class="mb-2 mt-4 text-sm font-medium text-gray-700">{{ __('New rider') }}</p>
                            <input x-model="name" maxlength="100" placeholder="{{ __('Rider name') }}" class="mb-2 h-12 w-full rounded-xl border border-gray-300 px-3 text-base">
                            <input x-model="phone" inputmode="tel" maxlength="20" placeholder="{{ __('Rider phone') }}" class="mb-3 h-12 w-full rounded-xl border border-gray-300 px-3 text-base">
                            <button type="submit" :disabled="!name.trim()" class="h-12 w-full rounded-xl bg-primary text-base font-semibold text-white disabled:opacity-40">{{ $open ? __('Save') : __('Start scanning') }}</button>
                            <button type="button" @click="pick = false" class="mt-2 h-11 w-full rounded-xl border border-gray-300 text-base text-gray-700">{{ __('Cancel') }}</button>
                        </form>
                    </div>
        @endif
    </div>
    @include('packaging._direct-print')
</x-layouts.app>
