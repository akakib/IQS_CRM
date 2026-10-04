{{-- Desktop table (md and up). Phones get the cards slot instead, never a table.
     <x-list.table>
         <x-slot:head><th>…</th></x-slot:head>
         <tr>…</tr>
     </x-list.table> --}}
@props(['head'])

<div {{ $attributes->merge(['class' => 'hidden overflow-x-auto rounded-xl border border-gray-200 bg-white md:block']) }} data-view="table">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500 [&_th]:px-4 [&_th]:py-3">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-gray-100 [&_td]:px-4 [&_td]:py-3 [&_tr:hover]:bg-gray-50">
            {{ $slot }}
        </tbody>
    </table>
</div>
