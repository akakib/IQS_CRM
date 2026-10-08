{{-- A channel's mark in its brand colour, so each row is known at a glance.
     Blade: <x-channel-icon type="whatsapp" />   Alpine (type from data): <x-channel-icon expr="c.kind" /> --}}
@props(['type' => null, 'expr' => null])

@php
    // [background, svg inner] per type. Simple shapes, white on the brand colour.
    $marks = [
        'whatsapp' => ['bg-[#25D366]', '<path fill="currentColor" d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Zm0 1.6a7.4 7.4 0 1 1-3.8 13.8l-.3-.2-2.7.7.7-2.6-.2-.3A7.4 7.4 0 0 1 12 4.6Zm-3 3.6c-.2 0-.5 0-.7.3-.2.3-.9.9-.9 2.1s.9 2.4 1 2.6c.1.2 1.8 2.8 4.4 3.8 2.1.8 2.6.7 3 .6.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2l-.4-.3-1.5-.7c-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1-.2-.1-1-.4-1.8-1.1-.7-.6-1.1-1.3-1.2-1.5-.1-.2 0-.4.1-.5l.4-.4.2-.4v-.4l-.7-1.7c-.2-.4-.3-.4-.5-.4H9Z"/>'],
        'messenger' => ['bg-[#0084FF]', '<path fill="currentColor" d="M12 3C7 3 3 6.7 3 11.4c0 2.6 1.3 5 3.4 6.5V21l3.1-1.7c.8.2 1.6.3 2.5.3 5 0 9-3.7 9-8.2S17 3 12 3Zm.9 11-2.3-2.4L6.2 14l4.9-5.2 2.3 2.4L17.8 9l-4.9 5Z"/>'],
        'comments' => ['bg-[#1877F2]', '<path fill="currentColor" d="M13.5 21v-7.5H16l.4-3h-2.9V8.6c0-.9.3-1.4 1.5-1.4h1.5V4.5c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2.3H8.1v3h2.5V21h2.9Z"/>'],
        'instagram' => ['bg-gradient-to-tr from-[#F58529] via-[#DD2A7B] to-[#8134AF]', '<rect x="4.5" y="4.5" width="15" height="15" rx="4.5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="16.6" cy="7.4" r="1.1" fill="currentColor"/>'],
        'telegram' => ['bg-[#229ED9]', '<path fill="currentColor" d="m4 11.5 15-5.8c.7-.3 1.3.2 1.1 1.2l-2.6 12c-.2.8-.7 1-1.4.6l-3.8-2.8-1.8 1.8c-.2.2-.4.3-.8.3l.3-3.9 7.1-6.4c.3-.3-.1-.4-.5-.2l-8.8 5.5-3.8-1.2c-.8-.2-.8-.8.2-1.1Z"/>'],
        'tiktok' => ['bg-black', '<path fill="currentColor" d="M16.6 5.8A4 4 0 0 1 15.5 3h-3v12.4a2.6 2.6 0 1 1-1.8-2.5V9.8a5.6 5.6 0 1 0 4.8 5.6V9.1a7 7 0 0 0 4 1.3v-3a4 4 0 0 1-2.9-1.6Z"/>'],
        'call' => ['bg-emerald-600', '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 4h3l1.5 4-2 1.3a11 11 0 0 0 7.2 7.2L16 14.5l4 1.5v3a1.5 1.5 0 0 1-1.6 1.5A16 16 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4Z"/>'],
        'rider' => ['bg-amber-500', '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 7h11v8H3zM14 10h4l3 3v2h-7M7 18a1.5 1.5 0 1 0 0-.01M17 18a1.5 1.5 0 1 0 0-.01"/>'],
        'other' => ['bg-gray-500', '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.4-4 8-9 8-1.5 0-3-.3-4.3-.9L3 20l1.4-3.7A7.4 7.4 0 0 1 3 12c0-4.4 4-8 9-8s9 3.6 9 8Z"/>'],
    ];
    $box = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white';
    [$bg, $svg] = $marks[$type] ?? $marks['other'];
    $keys = array_keys($marks);
@endphp

@if ($expr)
    @foreach ($marks as $key => [$markBg, $markSvg])
        <span x-show="(@js($keys).includes({{ $expr }}) ? {{ $expr }} : 'other') === @js($key)" {{ $attributes->merge(['class' => "$box $markBg"]) }} aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24">{!! $markSvg !!}</svg></span>
    @endforeach
@else
    <span {{ $attributes->merge(['class' => "$box $bg"]) }} aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24">{!! $svg !!}</svg></span>
@endif
