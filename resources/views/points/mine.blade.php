@php
    $final = (float) ($totals['final']->total ?? 0);
    $pending = (float) ($totals['pending']->total ?? 0);
    $revoked = (int) ($totals['revoked']->n ?? 0);
    $sign = fn ($v) => ($v > 0 ? '+' : '').rtrim(rtrim(number_format($v, 2), '0'), '.');
    $statusColor = ['pending' => 'amber', 'final' => 'green', 'revoked' => 'gray'];
    $months = collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->format('F Y')])->all();
@endphp

<x-layouts.app :heading="__('My points')">
    @if ($trial)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('Trial month: points are recorded so you can see how it works. They are not used for bonus yet.') }}</div>
    @endif

    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2" x-ref="f" @select-change="setTimeout(() => $refs.f.requestSubmit(), 0)">
        <x-simple-select name="month" :options="$months" :value="$month" />
        <x-simple-select name="per_page" :options="[25 => __(':n / page', ['n' => 25]), 50 => __(':n / page', ['n' => 50]), 100 => __(':n / page', ['n' => 100])]" :value="$perPage" />
        @if (request()->hasAny(['month', 'per_page']))
            <a href="{{ route('points.mine') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
        @endif
    </form>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3">
        <x-stat-tile :label="__('Points this month')" :value="$sign($final)" :trend="$final >= 0 ? 'up' : 'down'" :hint="__('Settled')" />
        <x-stat-tile :label="__('Waiting')" :value="$sign($pending)" :hint="__('Settle when the order ends')" />
        <x-stat-tile :label="__('Removed')" :value="$revoked" :hint="__('Order not delivered or dispute upheld')" class="col-span-2 md:col-span-1" />
    </div>

    @if ($entries->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No points this month yet.') }}</div>
    @else
        <div class="space-y-2">
            @foreach ($entries as $e)
                @php($rule = json_decode($e->rule_snapshot, true))
                <div class="rounded-xl border border-gray-200 bg-white p-3" x-data="{ open: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800">{{ $rule['name'] ?? $e->trigger_key }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                @if ($e->order_no)<a href="{{ route('orders.show', $e->order_id) }}" class="font-mono text-green-900 hover:underline">{{ $e->order_no }}</a> · @endif
                                {{ \Illuminate\Support\Carbon::parse($e->created_at)->format('d M, g:i A') }}
                                @if ($e->revoke_reason) · {{ $e->revoke_reason }} @endif
                                @if ($e->dispute_status) · {{ __('Dispute: :s', ['s' => $e->dispute_status]) }} @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-badge :color="$statusColor[$e->status]">{{ __(ucfirst($e->status)) }}</x-badge>
                            <span @class(['w-12 text-right text-sm font-semibold tabular-nums', 'text-green-800' => $e->points > 0, 'text-red-700' => $e->points < 0, 'line-through opacity-50' => $e->status === 'revoked'])>{{ $sign((float) $e->points) }}</span>
                        </div>
                    </div>
                    @if (\App\Http\Controllers\PointsController::canDispute($e, $disputeDays))
                        <button type="button" @click="open = !open" class="mt-2 text-xs font-medium text-green-900 hover:underline">{{ __('Dispute this') }}</button>
                        <form x-show="open" x-cloak method="POST" action="{{ route('points.dispute', $e->id) }}" class="mt-2 flex gap-2">
                            @csrf
                            <input name="note" required maxlength="500" placeholder="{{ __('Why is this wrong?') }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                            <x-button size="sm">{{ __('Send') }}</x-button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $entries->links() }}</div>
    @endif
</x-layouts.app>
