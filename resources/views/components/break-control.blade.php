{{-- Break: the header button (part="button") and, while a break is open,
     the full-screen break page (part="screen", placed at the end of <body> so
     it covers the sidebar and header too). Reasons load only when the dialog
     opens; the break screen costs two small queries, and only while on a break. --}}
@props(['part'])

@php $user = auth()->user(); @endphp

@if ($part === 'screen' && $user->current_break_id)
    @php
        $break = \Illuminate\Support\Facades\DB::table('staff_breaks as b')->leftJoin('status_reasons as r', 'r.id', '=', 'b.reason_id')
            ->where('b.id', $user->current_break_id)->first(['b.started_at', 'b.counts_as_break', 'r.label_en']);
        $limit = (int) settings('work.break_limit_minutes');
        $used = $break && $break->counts_as_break ? app(\App\Services\Work\BreakService::class)->countedMinutesToday($user->id) : 0;
        $over = $limit > 0 && $used > $limit;
        $seconds = $break ? (int) \Illuminate\Support\Carbon::parse($break->started_at)->diffInSeconds(now(), true) : 0;
    @endphp
    <div class="fixed inset-0 z-[200] flex items-center justify-center p-6 {{ $over ? 'bg-red-700' : 'bg-primary-dark' }}" role="dialog" aria-modal="true">
        <div class="w-full max-w-sm text-center text-white">
            <p class="text-xs font-semibold uppercase tracking-widest text-white/60">{{ $over ? __('Break limit passed') : __('On a break') }}</p>
            <p class="mt-4 text-2xl font-semibold">{{ $user->name }}</p>
            <p class="mt-1 font-mono text-sm text-white/70">ID #{{ $user->id }}</p>
            <p class="mt-6 text-sm text-white/80">{{ __($break->label_en ?? 'Break') }} · {{ __('since :t', ['t' => \Illuminate\Support\Carbon::parse($break->started_at ?? now())->format('g:i A')]) }}</p>
            <p class="mt-3"><x-countdown :seconds="$seconds" count-up bare class="text-6xl font-bold text-white" /></p>
            @if ($break && $break->counts_as_break && $limit > 0)
                <p class="mt-3 text-xs text-white/70">{{ __('Breaks today: :u of :l minutes', ['u' => $used, 'l' => $limit]) }}</p>
            @endif
            <form method="POST" action="{{ route('breaks.end') }}" class="mt-8">
                @csrf
                <button type="submit" autofocus class="w-full rounded-xl px-6 py-4 text-base font-semibold hover:opacity-90" style="background: #fff; color: {{ $over ? '#b91c1c' : 'var(--brand)' }}">{{ __('Start work') }}</button>
            </form>
        </div>
    </div>
@elseif ($part === 'button' && ! $user->current_break_id)
    <div x-data="{ open: false, reasons: null, reason: null,
            failed: false,
            async show() { this.open = true; this.failed = false; if (!this.reasons) { try { const r = await fetch(@js(route('breaks.reasons')), { headers: { Accept: 'application/json' } }); if (!r.ok) throw 0; this.reasons = await r.json() } catch (e) { this.failed = true } } } }">
        <button type="button" @click="show()" class="flex h-9 items-center gap-1.5 rounded-lg border border-gray-200 px-3 text-sm font-medium text-gray-600 hover:bg-gray-100">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="hidden sm:inline">{{ __('Break') }}</span>
        </button>

        {{-- Teleported to body: the sticky header would otherwise trap it under the sidebar. --}}
        <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4">
            <div class="absolute inset-0 bg-black/40"></div>
            <form method="POST" action="{{ route('breaks.start') }}" class="relative w-full max-w-sm rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-xl">
                @csrf
                <h3 class="text-sm font-semibold text-gray-800">{{ __('Take a break') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('Finish the orders you hold first. The screen stays locked until you press Start work.') }}</p>
                <input type="hidden" name="reason_id" :value="reason">
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <template x-if="!reasons && !failed"><p class="col-span-2 text-sm text-gray-400">{{ __('Loading…') }}</p></template>
                    <template x-if="failed"><button type="button" @click="show()" class="col-span-2 text-left text-sm text-red-600 hover:underline">{{ __('Could not load. Tap to try again.') }}</button></template>
                    <template x-for="r in reasons ?? []" :key="r.id">
                        <button type="button" @click="reason = r.id" x-text="r.label" class="rounded-lg border px-3 py-2.5 text-sm font-medium"
                            :class="reason === r.id ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"></button>
                    </template>
                </div>
                <div class="mt-5 flex gap-2">
                    <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Cancel') }}</button>
                    <button type="submit" :disabled="!reason" class="flex-1 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark disabled:opacity-50">{{ __('Start break') }}</button>
                </div>
            </form>
        </div>
        </template>
    </div>
@endif
