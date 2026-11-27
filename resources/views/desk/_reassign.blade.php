{{-- Order activity popup: who holds this order, and handing it to someone else.
     Pick the person and the reason, then confirm. Written to the order's history.
     Phone: one line, the way back on the left ($back = [url, label]) and Give to someone on the right;
     who holds it is in the order's own header there. --}}
<div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 max-sm:mb-2 max-sm:border-0 max-sm:bg-transparent max-sm:p-0"
    x-data="{ editing: false, to: null, toName: '', reason: null }"
    @select-change="reason = $event.detail">
    <div class="flex flex-wrap items-center justify-between gap-3">
        @if (! empty($back))<a href="{{ $back[0] }}" class="inline-flex items-center gap-1 text-sm font-medium text-primary sm:hidden">&larr; {{ $back[1] }}</a>@endif
        <div class="flex items-center gap-2 text-sm max-sm:hidden">
            <span class="text-gray-500">{{ __('Assigned to') }}</span>
            @if ($reassign['holder'])
                <x-avatar :name="$reassign['holder']->name" :photo="$reassign['holder']->photo_path" size="sm" />
                <b class="text-gray-900">{{ $reassign['holder']->name }}</b>
            @else
                <b class="text-gray-500">{{ __('Nobody') }}</b>
            @endif
        </div>
        <div class="flex items-center gap-2">
            {{-- Wider screens only: on a phone it sits in the courier box, beside the booking it undoes. --}}
            <x-take-back :order="$order" class="max-sm:hidden" />
            <button type="button" @click="editing = !editing" class="h-8 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 max-sm:h-auto max-sm:border-0 max-sm:px-0 max-sm:underline max-sm:decoration-gray-300 max-sm:underline-offset-4"
                x-text="editing ? @js(__('Cancel')) : @js($reassign['holder'] ? __('Give to someone else') : __('Give to someone'))"></button>
        </div>
    </div>

    <form id="reassign-form" x-show="editing" x-cloak method="POST" action="{{ route('orders.reassign', $order) }}" class="lockable mt-4 space-y-3 border-t border-gray-100 pt-4 max-sm:mt-2 max-sm:rounded-xl max-sm:border max-sm:border-gray-200 max-sm:bg-white max-sm:p-4">
        @csrf
        <input type="hidden" name="user_id" :value="to">
        <div>
            <p class="mb-2 text-xs font-medium uppercase text-gray-500">{{ __('Give it to') }}</p>
            <div class="flex flex-wrap gap-2">
                @forelse ($reassign['staff'] as $u)
                    <button type="button" @click="to = {{ $u->id }}; toName = @js($u->name)"
                        class="flex items-center gap-1.5 rounded-full border py-1 pl-1 pr-3 text-sm"
                        :class="to === {{ $u->id }} ? 'border-primary bg-primary-soft font-medium text-primary' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">
                        <x-avatar :name="$u->name" :photo="$u->photo_path" size="sm" /> {{ $u->name }}
                    </button>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No other staff can take orders.') }}</p>
                @endforelse
            </div>
        </div>
        <div>
            <p class="mb-2 text-xs font-medium uppercase text-gray-500">{{ __('Why') }}</p>
            <x-simple-select name="reason_id" :options="['' => __('Choose a reason')] + $reassign['reasons']" value="" full-width class="w-full" />
        </div>
        <button type="button" :disabled="!to || !reason"
            @click="$dispatch('open-confirm', { id: 'reassign-confirm', form: 'reassign-form', label: @js($order->order_no), verb: @js(__('Give')),
                message: @js($reassign['holder'] ? __('From :a to', ['a' => $reassign['holder']->name]) : __('To')) + ' ' + toName + '. ' + @js(__('Their timer starts when they open it.')), danger: false })"
            class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-dark disabled:opacity-40"
            x-text="to ? @js(__('Give to')) + ' ' + toName : @js(__('Pick a person'))"></button>
    </form>
    <x-confirm-modal id="reassign-confirm" :verb="__('Give')" :danger="false" />
</div>
