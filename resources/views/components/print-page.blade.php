{{-- The one way every list is downloaded as PDF: a clean page that opens the browser's Print / Save as PDF.
     The page sets no top or bottom paper margin, so the browser has no room for its own date, title, page
     link and page number; the frame below gives every printed page its white space back instead.
     <x-print-page :title="$name"> ...content... <x-slot:styles>css</x-slot:styles> <x-slot:scripts>js</x-slot:scripts> </x-print-page>
     A list's buttons are Print, PDF (?as=pdf) and Excel, the first two opening this page in a new tab.
     back: a page opened in place gets Back instead of Close (never both). The main button is Print, or Save as PDF
     on the PDF page; extra actions must not repeat it. --}}
@props(['title', 'landscape' => true, 'autoprint' => true, 'back' => null])
@php
    // ?as=pdf: the same page, saved as a PDF file (the browser's dialog, with Save as PDF to pick); otherwise it is for the printer.
    $pdf = request()->query('as') === 'pdf';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 0 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, 'Segoe UI', 'Noto Sans Bengali', Arial, sans-serif; font-size: 10.5px; color: #111; background: #fff; }
        .bar { display: flex; gap: 8px; justify-content: flex-end; padding: 10px 16px; border-bottom: 1px solid #e5e7eb; }
        .bar button, .bar a { font: inherit; font-size: 13px; padding: 6px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; color: #374151; cursor: pointer; text-decoration: none; min-height: 32px; display: inline-flex; align-items: center; }
        .bar .left { margin-right: auto; }
        .bar .go { background: #0d542b; border-color: #0d542b; color: #fff; }
        .page { padding: 12px 16px; }
        /* White space at the top and bottom of every printed page (repeats on each page). */
        .frame { width: 100%; border-collapse: collapse; }
        .frame > thead > tr > td, .frame > tfoot > tr > td { height: 10mm; padding: 0; border: 0; }
        .frame > tbody > tr > td { padding: 0; border: 0; }
        .frame > thead, .frame > tfoot { display: none; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #4b5563; margin-bottom: 8px; }
        .sum { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 6px 0 10px; font-size: 12px; }
        .sum b { font-size: 13px; }
        table.list { width: 100%; border-collapse: collapse; }
        .list th, .list td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
        .list th { background: #eaf3de; font-weight: 600; }
        .list td.n, .list th.n { text-align: right; white-space: nowrap; }
        .list tr { page-break-inside: avoid; }
        .list thead { display: table-header-group; }
        .warn { color: #b45309; margin: 6px 0; }
        .hint { margin: 0; padding: 8px 16px; background: #fffbeb; color: #92400e; font-size: 13px; border-bottom: 1px solid #fde68a; }
        @media print {
            .hint { display: none; }
            .bar { display: none; }
            .page { padding: 0; }
            .frame > thead { display: table-header-group; }
            .frame > tfoot { display: table-footer-group; }
            .list th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        {{ $styles ?? '' }}
    </style>
</head>
<body>
    <div class="bar">
        {{-- One way out: Back when the page was opened in place, Close when it opened in its own tab. --}}
        @if ($back)
            <a href="{{ $back }}" class="left">{{ __('Back') }}</a>
        @endif
        {{ $actions ?? '' }}
        @unless ($back)
            <button type="button" onclick="window.close()">{{ __('Close') }}</button>
        @endunless
        <button type="button" class="go" onclick="window.print()">{{ $pdf ? __('Save as PDF') : __('Print') }}</button>
    </div>
    @if ($pdf)
        <p class="hint">{{ __('In the window that opens, set Destination to "Save as PDF", then press Save.') }}</p>
    @endif
    <table class="frame">
        <thead><tr><td></td></tr></thead>
        <tfoot><tr><td></td></tr></tfoot>
        <tbody><tr><td><div class="page">{{ $slot }}</div></td></tr></tbody>
    </table>
    {{ $scripts ?? '' }}
    {{-- Opens Print / Save as PDF at once; Close or Escape leaves the page to read or sort first. --}}
    @if ($autoprint || request()->has('as'))
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
