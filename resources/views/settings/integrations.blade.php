<x-layouts.app :heading="__('Website connection')">
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="__('Website orders → IQS (webhook)')">
            <ol class="mb-4 list-inside list-decimal space-y-1 text-sm text-gray-600">
                <li>{{ __('WordPress admin > WooCommerce > Settings > Advanced > Webhooks > Add webhook.') }}</li>
                <li>{{ __('Status: Active. Topic: Order created. API version: WP REST API v3.') }}</li>
                <li>{{ __('Delivery URL and Secret: copy from below.') }}</li>
            </ol>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Delivery URL') }}</p>
            <p class="mb-3 break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm">{{ $webhookUrl }}</p>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Secret') }}</p>
            @if ($secret)
                <div x-data="{ show: false }" class="flex items-center gap-2">
                    <p class="flex-1 break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm" x-text="show ? @js($secret) : '••••••••••••••••'"></p>
                    <button type="button" @click="show = !show" class="text-sm text-green-900 hover:underline" x-text="show ? @js(__('Hide')) : @js(__('Show'))"></button>
                </div>
            @else
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('Not set. Ask for WOO_WEBHOOK_SECRET to be added to the server settings.') }}</p>
            @endif
        </x-card>

        <x-card :title="__('Steadfast → IQS (delivery status webhook)')">
            <p class="mb-3 text-sm text-gray-600">{{ __('Steadfast merchant panel > webhook: paste this URL and set the Bearer token below.') }}</p>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Callback URL') }}</p>
            <p class="mb-3 break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm">{{ $steadfastUrl }}</p>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Bearer token') }}</p>
            @if ($steadfastToken)
                <div x-data="{ show: false }" class="flex items-center gap-2">
                    <p class="flex-1 break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm" x-text="show ? @js($steadfastToken) : '••••••••••••••••'"></p>
                    <button type="button" @click="show = !show" class="text-sm text-green-900 hover:underline" x-text="show ? @js(__('Hide')) : @js(__('Show'))"></button>
                </div>
            @else
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('Not set (STEADFAST_WEBHOOK_TOKEN).') }}</p>
            @endif
        </x-card>

        <x-card :title="__('IQS → website (product sync)')">
            <p class="text-sm text-gray-600">{{ __('Price, stock status, description and SEO changes are pushed to linked website products.') }}</p>
            <p class="mt-3 text-sm">{{ __('Current mode:') }}
                <x-badge :color="$storeDriver === 'fake' ? 'amber' : 'green'">{{ $storeDriver === 'fake' ? __('Test mode, nothing is sent') : 'WooCommerce' }}</x-badge>
            </p>
        </x-card>
    </div>

    <x-card :title="__('Incoming website orders')" class="mt-6">
        <p class="mb-3 text-xs text-gray-500">
            @foreach ($counts as $status => $n)<span class="mr-3">{{ ucfirst($status) }}: {{ $n }}</span>@endforeach
        </p>
        @forelse ($inbox as $row)
            <div class="flex flex-col gap-1 border-b border-gray-50 py-2 text-sm last:border-0 md:flex-row md:items-center md:justify-between">
                <span>
                    <span class="font-mono">{{ $row->external_id }}</span>
                    <span class="text-xs text-gray-400">· {{ \Illuminate\Support\Carbon::parse($row->received_at)->format('d M, g:i A') }}</span>
                    @if ($row->error)<span class="block text-xs text-red-600">{{ $row->error }}</span>@endif
                </span>
                <span class="flex items-center gap-2">
                    @if ($row->order_id)<a href="{{ route('orders.show', $row->order_id) }}" class="text-xs text-green-900 hover:underline">{{ __('Open order') }}</a>@endif
                    <x-badge :color="['processed' => 'green', 'failed' => 'red', 'received' => 'blue', 'ignored' => 'gray'][$row->status]">{{ ucfirst($row->status) }}</x-badge>
                    @if ($row->status === 'failed')
                        <form method="POST" action="{{ route('settings.integrations.retry', $row->id) }}">@csrf<x-button size="sm" variant="secondary">{{ __('Retry') }}</x-button></form>
                    @endif
                </span>
            </div>
        @empty
            <p class="text-sm text-gray-400">{{ __('No website orders received yet.') }}</p>
        @endforelse
    </x-card>
</x-layouts.app>
