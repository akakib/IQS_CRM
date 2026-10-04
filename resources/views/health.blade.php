@php
    $ok = 'bg-green-50 text-green-800';
    $warn = 'bg-amber-50 text-amber-800';
    $bad = 'bg-red-50 text-red-700';
    $backupState = ! $backup ? $bad : ($backup['at']->lt(now()->subHours(26)) ? $warn : $ok);
    $schedulerState = ! $scheduler ? $bad : ($scheduler->lt(now()->subMinutes(5)) ? $warn : $ok);
    $queueState = $queue['failed'] > 0 || ($queue['oldest'] && $queue['oldest']->lt(now()->subMinutes(10))) ? $warn : $ok;
@endphp

<x-layouts.app :heading="__('System health')">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Last backup') }}</p>
            <p class="mt-2 text-lg font-semibold text-gray-800">{{ $backup ? $backup['at']->diffForHumans() : __('Never') }}</p>
            <p class="text-xs text-gray-500">{{ $backup ? $backup['at']->format('d M Y, g:i A').' · '.number_format($backup['size'] / 1024, 1).' KB' : '' }}</p>
            <span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $backupState }}">
                {{ trans_choice(':count file kept|:count files kept', $backupCount, ['count' => $backupCount]) }} · {{ $offServer ? __('copied to :disk', ['disk' => $offServer]) : __('server only') }}
            </span>
            @if ($backupError)
                <p class="mt-2 text-xs text-red-600">{{ __('Last attempt failed: :m', ['m' => $backupError['message']]) }}</p>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Queue backlog') }}</p>
            <p class="mt-2 text-lg font-semibold text-gray-800">{{ trans_choice(':count job waiting|:count jobs waiting', $queue['pending'], ['count' => $queue['pending']]) }}</p>
            <p class="text-xs text-gray-500">{{ $queue['oldest'] ? __('oldest :t', ['t' => $queue['oldest']->diffForHumans()]) : __('nothing waiting') }}</p>
            <span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $queueState }}">{{ trans_choice(':count failed|:count failed', $queue['failed'], ['count' => $queue['failed']]) }}</span>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Scheduler (cron)') }}</p>
            <p class="mt-2 text-lg font-semibold text-gray-800">{{ $scheduler ? $scheduler->diffForHumans() : __('Not running') }}</p>
            <span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $schedulerState }}">{{ $scheduler ? __('last run') : __('Add the cron job in hPanel') }}</span>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Environment') }}</p>
            <p class="mt-2 text-lg font-semibold text-gray-800">{{ ucfirst($env) }}</p>
            <span @class(['mt-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium', $ok => $courier === 'fake', $warn => $courier !== 'fake'])>
                {{ __('Courier: :d', ['d' => $courier === 'fake' ? __('fake (no real parcels)') : $courier]) }}
            </span>
        </div>
    </div>

    @unless ($scheduler)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-medium">{{ __('One cron job runs queues, backups and clean-up.') }}</p>
            <p class="mt-1">{{ __('hPanel > Advanced > Cron Jobs, every minute:') }}</p>
            <code class="mt-2 block overflow-x-auto rounded bg-white px-3 py-2 text-xs text-gray-800">cd {{ base_path() }} &amp;&amp; {{ PHP_BINARY }} artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
        </div>
    @endunless
</x-layouts.app>
