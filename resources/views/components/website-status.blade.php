{{-- A website order's own status on the website, and Woo Record Verified to set it there without leaving the page
     (done by itself on record verified; this is for when it did not go). Used on the Desk and the order page.
     <x-website-status :order="$order" /> --}}
@props(['order'])
@php $push = app(\App\Services\Orders\WebsiteStatusPush::class); @endphp
@if ($order->channel === 'web' && $order->external_ref)
    <div x-data="{
            status: @js(\Illuminate\Support\Str::headline((string) ($order->external_status ?: __('not known')))),
            done: @js($order->external_status === \App\Services\Orders\WebsiteStatusPush::STATUS && $order->website_pushed_at),
            at: @js($order->website_pushed_at?->format('d M, g:i A')),
            error: @js($order->website_push_error ? \Illuminate\Support\Str::limit($order->website_push_error, 90) : null),
            canSend: @js($push->canSend($order)), busy: false,
            async send() {
                this.busy = true;
                try {
                    const r = await fetch(@js(route('orders.website-status', $order)), { method: 'POST', headers: { 'X-CSRF-TOKEN': @js(csrf_token()), Accept: 'application/json' } });
                    const d = await r.json();
                    if (r.ok && d.ok) { this.status = d.status; this.done = true; this.at = d.at; this.error = null; this.canSend = false; window.toast(d.message) }
                    else { this.error = d.error || d.message || @js(__('The website did not answer.')); window.toast(this.error, 'error') }
                } catch (e) { this.error = @js(__('The website did not answer.')); window.toast(this.error, 'error') }
                this.busy = false;
            },
        }" {{ $attributes->merge(['class' => 'mt-2 flex flex-wrap items-center gap-2 rounded-lg px-3 py-2 text-xs']) }}
        :class="error ? 'bg-amber-50 text-amber-900' : 'bg-gray-50 text-gray-700'">
        <span>{{ __('Website status:') }} <b x-text="status"></b><span x-show="done" class="text-green-700"> ✓ {{ __('set') }} <span x-text="at"></span></span></span>
        <span x-show="error" x-cloak>· {{ __('Not set:') }} <span x-text="error"></span></span>
        @if ($push->canSend($order))
            <button type="button" x-show="canSend" @click="send()" :disabled="busy"
                class="lockable rounded-lg border border-gray-300 bg-white px-2.5 py-1 font-semibold text-gray-800 hover:border-primary hover:text-primary disabled:opacity-50"
                x-text="busy ? @js(__('Sending…')) : @js(__('Woo Record Verified'))"></button>
        @endif
    </div>
@endif
