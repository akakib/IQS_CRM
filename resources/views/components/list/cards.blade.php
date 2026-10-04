{{-- Phone list: one x-record-card per row. Hidden from md up (the table shows there). --}}
<div {{ $attributes->merge(['class' => 'space-y-3 md:hidden']) }} data-view="cards">
    {{ $slot }}
</div>
