{{-- Ask the courier now for the status of every parcel on its way (the same as the cron every 30 minutes),
     with when it was last asked. For booking, Parcel status and Delivery issues people.
     <x-courier-resync /> --}}
@canany(['shipping.create', 'hotline.view'])
    @php($at = \Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\ShippingController::RESYNC_AT))
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-end gap-2']) }}>
        @if ($at)<span class="text-xs text-gray-500">{{ __('Last checked :t', ['t' => \Illuminate\Support\Carbon::parse($at)->format('g:i A')]) }}</span>@endif
        <form method="POST" action="{{ route('shipping.resync') }}">@csrf<x-button size="sm" variant="secondary">{{ __('Re-sync courier status') }}</x-button></form>
    </div>
@endcanany
