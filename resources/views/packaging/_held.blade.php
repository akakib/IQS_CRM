{{-- Orders on hold in the shop (reached the packers, then held): the full page and the packer's page. --}}
            @forelse ($heldInShop as $h)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono text-base font-bold text-gray-900">{{ $h->order_no }} @if ($h->label)<span class="text-sm font-medium text-gray-600">· {{ __('Label :l', ['l' => $h->label]) }}</span>@endif</p>
                            <p class="mt-0.5 text-sm font-medium text-amber-900">{{ $h->reason ? __($h->reason) : __('On hold') }}@if ($h->hold_expected_date) · {{ __('until :d', ['d' => \Illuminate\Support\Carbon::parse($h->hold_expected_date)->format('d M')]) }}@endif</p>
                            @if ($h->hold_note)<p class="mt-0.5 text-sm text-gray-700">“{{ \App\Support\Phone::hideIn($h->hold_note) }}”</p>@endif
                        </div>
                        <span class="shrink-0 text-right text-xs text-gray-500">{{ $h->ship_district ?: '-' }}@if ($h->moderator)<br>{{ __('by :n', ['n' => $h->moderator]) }}@endif</span>
                    </div>
                    <p class="mt-2 text-xs font-semibold text-amber-900">{{ __('Set the parcel aside with its label. Do not send it.') }}</p>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No order on hold in the shop.') }}</div>
            @endforelse
