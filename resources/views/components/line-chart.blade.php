{{-- Inline SVG line chart (no JS library). rows = list of arrays, series = key => [label, hex colour].
     Tap or hover a column to read that day's numbers above the graph. Drawn twice
     (wide for md and up, narrow for phones) so text stays readable at both sizes.
     <x-line-chart :rows="$report['rows']" :series="['placed' => [__('Placed'), '#1f6f43'], 'cancelled' => [__('Cancelled'), '#dc2626']]" /> --}}
@props(['rows', 'series', 'labelKey' => 'label', 'height' => 220])

@php
    $rows = array_values($rows);
    $n = count($rows);
    $max = 0;
    foreach ($rows as $r) { foreach ($series as $k => $_) { $max = max($max, (float) ($r[$k] ?? 0)); } }
    // A "nice" step (1, 2, 5 × 10^k) so gridlines land on round numbers, 3 to 6 of them.
    $step = 1;
    foreach ([1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000, 50000, 100000] as $candidate) {
        $step = $candidate;
        if ($max / $candidate <= 6) { break; }
    }
    $top = max($step * 3, (int) (ceil($max / $step) * $step));
    $gridLines = (int) ($top / $step);
    $data = array_map(fn ($r) => ['l' => $r[$labelKey]] + array_intersect_key($r, $series), $rows);
    $sizes = ['hidden md:block' => [720, (int) $height, 8], 'md:hidden' => [360, (int) round($height * 0.9), 4]];
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }} x-data="{ i: null, rows: @js($data) }">
    <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
        @foreach ($series as $k => [$label, $color])
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $color }}"></span>{{ $label }}
                <span class="font-semibold tabular-nums text-gray-800" x-text="i === null ? '' : rows[i]['{{ $k }}']"></span></span>
        @endforeach
        <span class="ml-auto font-medium text-gray-800" x-text="i === null ? @js(__('Tap a day to see its numbers')) : rows[i].l"></span>
    </div>
    @if ($n === 0)
        <p class="py-10 text-center text-sm text-gray-500">{{ __('Nothing in this period.') }}</p>
    @else
        @foreach ($sizes as $visibility => [$w, $h, $maxLabels])
            @php
                $padL = 36; $padR = 20; $padT = 14; $padB = 26;
                $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB;
                $x = fn (int $i) => $n > 1 ? $padL + $plotW * $i / ($n - 1) : $padL + $plotW / 2;
                $y = fn (float $v) => $padT + $plotH - ($top > 0 ? $plotH * $v / $top : 0);
                $labelEvery = max(1, (int) ceil($n / $maxLabels));
                $lastLabelled = (int) (floor(($n - 1) / $labelEvery) * $labelEvery);
                $colW = $n > 1 ? $plotW / ($n - 1) : $plotW;
            @endphp
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="{{ $visibility }} h-auto w-full select-none" role="img" aria-label="{{ implode(', ', array_map(fn ($s) => $s[0], $series)) }}" @mouseleave="i = null">
                @for ($g = 0; $g <= $gridLines; $g++)
                    @php($v = $step * $g)
                    <line x1="{{ $padL }}" x2="{{ $w - $padR }}" y1="{{ round($y($v), 1) }}" y2="{{ round($y($v), 1) }}" stroke="#e5e7eb" stroke-width="1" />
                    <text x="{{ $padL - 6 }}" y="{{ round($y($v) + 3, 1) }}" text-anchor="end" font-size="10" fill="#9ca3af">{{ number_format($v) }}</text>
                @endfor
                @foreach ($rows as $i => $r)
                    {{-- Every Nth label, plus the last point unless it would sit on top of the previous label. --}}
                    @if ($i % $labelEvery === 0 || ($i === $n - 1 && $i - $lastLabelled > $labelEvery / 2))
                        <text x="{{ round($x($i), 1) }}" y="{{ $h - 8 }}" text-anchor="{{ $i === $n - 1 && $n > 1 ? 'end' : ($i === 0 ? 'start' : 'middle') }}" font-size="10" fill="#6b7280">{{ $r[$labelKey] }}</text>
                    @endif
                @endforeach
                @foreach ($series as $k => [$label, $color])
                    @php($pts = implode(' ', array_map(fn ($i, $r) => round($x($i), 1).','.round($y((float) ($r[$k] ?? 0)), 1), array_keys($rows), $rows)))
                    <polyline points="{{ $pts }}" fill="none" stroke="{{ $color }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                @endforeach
                {{-- One invisible column per point: hover or tap to select it. --}}
                @foreach ($rows as $i => $r)
                    <rect x="{{ round($x($i) - $colW / 2, 1) }}" y="{{ $padT }}" width="{{ round($colW, 1) }}" height="{{ $plotH }}" fill="transparent" @mouseenter="i = {{ $i }}" @click="i = {{ $i }}" />
                    <line x1="{{ round($x($i), 1) }}" x2="{{ round($x($i), 1) }}" y1="{{ $padT }}" y2="{{ $padT + $plotH }}" stroke="#9ca3af" stroke-width="1" stroke-dasharray="3 3" x-show="i === {{ $i }}" x-cloak />
                    @foreach ($series as $k => [$label, $color])
                        <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y((float) ($r[$k] ?? 0)), 1) }}" r="3.5" fill="{{ $color }}" x-show="i === {{ $i }}" x-cloak />
                    @endforeach
                @endforeach
            </svg>
        @endforeach
    @endif
</div>
