{{-- "Booked by mistake": a booked order goes back to Call. Shown only to
     someone allowed to do it (see DeskService::canTakeBack).
     <x-take-back :order="$order" /> --}}
@props(['order'])

@if (auth()->user() && app(\App\Services\Orders\DeskService::class)->canTakeBack($order, auth()->user()))
    <div x-data="{ open: false }" {{ $attributes }}>
        <button type="button" @click="open = true" class="lockable rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('Booked by mistake') }}</button>
        <div x-show="open" x-cloak class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center sm:px-4" @keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
            <form method="POST" action="{{ route('orders.take-back', $order) }}" class="relative w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-xl">
                @csrf
                <h3 class="text-base font-semibold text-gray-900">{{ __('Take :no back to Call?', ['no' => $order->order_no]) }}</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600">
                    <li>{{ __('The label stops working, so it can never be packed or sent.') }}</li>
                    <li>{{ __('Delete the parcel in the courier panel, then press Deleted on the order.') }}</li>
                    @if ($order->packer_id || $order->packed_at)
                        <li class="font-medium text-red-700">{{ __('A packer already started it: they are told to open the box and put the items back.') }}</li>
                    @endif
                    <li>{{ __('It can be confirmed again only after the parcel is deleted.') }}</li>
                </ul>
                <label class="mt-3 block text-sm text-gray-700">{{ __('What went wrong?') }}
                    <textarea name="why" required minlength="5" maxlength="300" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none" placeholder="{{ __('e.g. customer had not confirmed yet') }}"></textarea>
                </label>
                @error('why')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Keep it') }}</button>
                    <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">{{ __('Take back') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
