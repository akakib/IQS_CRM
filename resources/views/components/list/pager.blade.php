{{-- Pages for a list already on the page. The parent holds the state and
     each row shows only on its page:
     <div x-data="{ page: 1, per: 20, total: 57 }">
         rows with x-show="Math.ceil(n / per) === page"  (n = the row's number, from 1)
         <x-list.pager />
     </div> --}}
<div x-show="total > per" x-cloak class="mt-3 flex items-center justify-between gap-3 text-sm">
    <span class="text-gray-500" x-text="`${(page - 1) * per + 1} to ${Math.min(page * per, total)} of ${total}`"></span>
    <span class="flex items-center gap-2">
        <button type="button" @click="page--" :disabled="page === 1" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Previous') }}</button>
        <span class="tabular-nums text-gray-600" x-text="`${page} / ${Math.ceil(total / per)}`"></span>
        <button type="button" @click="page++" :disabled="page * per >= total" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Next') }}</button>
    </span>
</div>
