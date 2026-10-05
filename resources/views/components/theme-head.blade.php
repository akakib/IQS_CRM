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
<style>
    :root { --brand: {{ $brand }};@if ($family) --app-font: '{{ $font }}';@elseif ($font === 'System default') --app-font: system-ui;@endif }
</style>
<script>
    try { if (localStorage.getItem('iqs_theme') === 'dark') document.documentElement.classList.add('dark') } catch (e) {}
    try { if (localStorage.getItem('iqs_sidebar_mini') === '1') document.documentElement.classList.add('sidebar-mini') } catch (e) {}
</script>
