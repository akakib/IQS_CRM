{{-- History as a timeline: one line down the left, one dot per entry, newest
     first. Each entry: what happened, then who and when underneath.
     <x-timeline :entries="$notes" />            entries have note_type, body, user, created_at
     <x-timeline :entries="$notes" with-year />  full date (order page) --}}
@props(['entries', 'withYear' => false])

@php
    // Dot colour and icon per kind of entry.
    $kinds = [
        'status' => ['bg-primary text-white', 'swap'],
        'call' => ['bg-blue-600 text-white', 'phone'],
        'chat' => ['bg-blue-600 text-white', 'document'],
        'assignment' => ['bg-gray-500 text-white', 'users'],
        'verification' => ['bg-teal-600 text-white', 'shield'],
        'courier' => ['bg-purple-600 text-white', 'truck'],
        'rider' => ['bg-purple-600 text-white', 'truck'],
        'payment' => ['bg-amber-500 text-white', 'cash'],
        'amendment' => ['bg-orange-500 text-white', 'tag'],
        'manual' => ['bg-gray-500 text-white', 'document'],
        'scan' => ['bg-red-600 text-white', 'box'],
    ];
    $default = ['bg-gray-400 text-white', 'dot'];
    $count = count($entries);
@endphp

<ol {{ $attributes }}>
    @foreach ($entries as $entry)
        @php [$dot, $mark] = $kinds[$entry->note_type] ?? $default; @endphp
        <li class="relative flex gap-3 pb-4 last:pb-0">
            {{-- The line: from this dot down to the next one. --}}
            @if ($loop->iteration < $count)
                <span class="absolute left-[11px] top-6 -bottom-0 w-px bg-gray-200" aria-hidden="true"></span>
            @endif
            <span class="relative z-[1] flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $dot }}"><x-icon :name="$mark" class="h-3.5 w-3.5" /></span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="break-words text-sm text-gray-800">{{ $entry->body }}</p>
                <p class="mt-0.5 text-xs text-gray-400">{{ $entry->user ?? __('System') }} · {{ \Illuminate\Support\Carbon::parse($entry->created_at)->format($withYear ? 'd M Y, g:i A' : 'd M, g:i A') }}</p>
            </div>
        </li>
    @endforeach
</ol>
