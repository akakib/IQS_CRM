{{-- More holds: who asked, why, and the number to give them (HoldLimitController). Managers only. --}}
<x-layouts.app :heading="__('More holds')">
    <x-card :title="__('Waiting for you')" class="mb-5">
        @forelse ($pending as $r)
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 py-3 last:border-0">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-900">{{ $r->name }} <span class="font-normal text-gray-500">· {{ __('can hold :n now', ['n' => $r->current_limit]) }}</span></p>
                    <p class="mt-1 text-sm text-gray-700">{{ $r->reason }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d M, g:i A') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('hold-limits.decide', $r->id) }}" class="flex items-center gap-2">
                        @csrf
                        <input type="hidden" name="approve" value="1">
                        <label class="text-xs text-gray-600">{{ __('New number') }}
                            <input name="new_limit" type="number" min="1" max="50" required value="{{ min(50, $r->current_limit + 5) }}" class="ml-1 w-20 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                        </label>
                        <x-button size="sm">{{ __('Approve') }}</x-button>
                    </form>
                    <form method="POST" action="{{ route('hold-limits.decide', $r->id) }}">
                        @csrf
                        <input type="hidden" name="approve" value="0">
                        <x-button size="sm" variant="secondary">{{ __('Reject') }}</x-button>
                    </form>
                </div>
            </div>
        @empty
            <p class="py-4 text-center text-sm text-gray-500">{{ __('No request is waiting.') }}</p>
        @endforelse
    </x-card>

    @if ($recent->isNotEmpty())
        <x-card :title="__('Answered')">
            @foreach ($recent as $r)
                <p class="border-b border-gray-100 py-2 text-sm text-gray-700 last:border-0">
                    <b>{{ $r->name }}</b> · {{ $r->status === 'approved' ? __(':a to :b', ['a' => $r->current_limit, 'b' => $r->new_limit]) : __('Rejected') }}
                    <span class="text-gray-500">· {{ __('by :n', ['n' => $r->decider ?? '-']) }} · {{ \Illuminate\Support\Carbon::parse($r->decided_at)->format('d M, g:i A') }}</span>
                </p>
            @endforeach
        </x-card>
    @endif
</x-layouts.app>
