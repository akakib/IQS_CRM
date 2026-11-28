{{-- Goes inside <head> of every layout: the brand colour and font chosen in
     Settings > Appearance, and the dark-mode switch applied before the page
     paints (so a dark page never flashes white). --}}
@php
    $brand = (string) settings('appearance.primary_color');
    $brand = preg_match('/^#[0-9a-fA-F]{6}$/', $brand) ? $brand : '#0d542b';
    $font = (string) settings('appearance.font');
    $fonts = config('appearance.fonts');
    $family = $fonts[$font] ?? null;
@endphp
@if ($family)
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family={{ $family }}&display=swap">
@endif
{{-- Installable on phones (Android: Install app, iPhone: Add to Home Screen). sw.js keeps no data, only the No internet page. --}}
{{-- Bangla text only (owner 2026-11-29): Hind Siliguri sits after the main font, so English keeps its font and only Bangla letters
     take this one; the browser fetches just the Bengali part, and only on a page that shows Bangla. --}}
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=hind-siliguri:400,500,600,700&display=swap">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="{{ $brand }}">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="T5 CRM">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<style>
    :root { --brand: {{ $brand }};@if ($family) --app-font: '{{ $font }}';@elseif ($font === 'System default') --app-font: system-ui;@endif }
</style>
<script>
    try { if (localStorage.getItem('iqs_theme') === 'dark') document.documentElement.classList.add('dark') } catch (e) {}
    try { if (localStorage.getItem('iqs_sidebar_mini') === '1') document.documentElement.classList.add('sidebar-mini') } catch (e) {}
    // iPhone zooms the page in when a box with text under 16px is tapped, and the page then runs off the right edge.
    // maximum-scale=1 stops that; iPhone still lets people pinch to zoom (Android is left as it is).
    if (/iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
        const vp = document.querySelector('meta[name=viewport]'); if (vp) vp.content = 'width=device-width, initial-scale=1, maximum-scale=1';
    }
    if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
</script>
