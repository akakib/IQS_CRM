<x-layouts.app :heading="__('Notifications')">
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">{{ __('Everything sent to you. Nothing is ever deleted.') }}</p>
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <button type="submit" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Mark all read') }}</button>
        </form>
    </div>

    @if ($items->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No notifications yet.') }}</div>
    @else
        <div class="space-y-2">
            @foreach ($items as $n)
                <a href="{{ route('notifications.open', $n->id) }}" @class([
                    'block rounded-xl border bg-white p-3 hover:bg-gray-50',
                    'border-red-200 bg-red-50' => $n->priority === 'urgent' && ! $n->acted_at,
                    'border-gray-200' => ! ($n->priority === 'urgent' && ! $n->acted_at),
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p @class(['text-sm text-gray-800', 'font-semibold' => ! $n->read_at])>
                                {{ $n->title }}@if ($n->group_count > 1) <span class="text-gray-500">(×{{ $n->group_count }})</span>@endif
                            </p>
                            @if ($n->body)<p class="text-sm text-gray-500">{{ $n->body }}</p>@endif
                            <p class="mt-1 text-xs text-gray-400">{{ $n->type }} · {{ \Illuminate\Support\Carbon::parse($n->created_at)->format('d M Y, g:i A') }}</p>
                        </div>
                        @if ($n->priority === 'urgent')
                            <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs font-medium', 'bg-red-100 text-red-700' => ! $n->acted_at, 'bg-gray-100 text-gray-500' => $n->acted_at])>
                                {{ $n->acted_at ? __('Done') : __('Urgent') }}
                            </span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $items->links() }}</div>
    @endif
</x-layouts.app>
