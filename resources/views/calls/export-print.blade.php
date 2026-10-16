{{-- A call list's numbers for paper or PDF, the same way as every list. --}}
<x-print-page :title="$title">
    <x-slot:styles>
        .bad { color: #b91c1c; } .good { color: #166534; }
    </x-slot:styles>
        <h1>{{ $list->name }}</h1>
        <div class="meta">{{ $describe }} · {{ __('Printed :t by :n', ['t' => now()->format('d M Y, g:i A'), 'n' => auth()->user()->name]) }}</div>
        <div class="sum">
            <span>{{ __('Numbers') }}: <b>{{ number_format($rows->count()) }}</b></span>
            <span>{{ __('Calls') }}: <b>{{ number_format($overview['calls']) }}</b></span>
            <span>{{ __('Talked') }}: <b>{{ number_format($overview['talked']) }}</b></span>
            <span>{{ __('Happy') }}: <b class="good">{{ $overview['happy'] }}</b></span>
            <span>{{ __('Okay') }}: <b>{{ $overview['okay'] }}</b></span>
            <span>{{ __('Unhappy') }}: <b class="bad">{{ $overview['unhappy'] }}</b></span>
            <span>{{ __('Satisfaction') }}: <b>{{ $overview['satisfaction'] === null ? '-' : $overview['satisfaction'].'%' }}</b></span>
            <span>{{ __('Orders') }}: <b>{{ $overview['orders'] }} · ৳{{ number_format($overview['sold']) }}</b></span>
        </div>
        @if ($rows->count() >= $max)
            <p class="warn">{{ __('Only the first :n numbers are listed. Pick fewer days or a filter.', ['n' => number_format($max)]) }}</p>
        @endif
        @if ($rows->isEmpty())
            <p>{{ __('No number matches these filters.') }}</p>
        @else
            <table class="list">
                <thead>
                    <tr>
                        <th>#</th><th>{{ __('Customer') }}</th><th>{{ __('Phone') }}</th><th>{{ __('State') }}</th><th>{{ __('Result') }}</th><th class="n">{{ __('Satisfied') }}</th>
                        <th>{{ __('Good about') }}</th><th>{{ __('Bad about') }}</th><th>{{ __('Note') }}</th><th class="n">{{ __('Tries') }}</th><th>{{ __('Last call') }}</th><th>{{ __('Order') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $n)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $n->name }}</td>
                            <td>{{ $seePhone ? $n->phone : \App\Support\Permissions\Mask::value($n->phone, 'customer_contact') }}</td>
                            <td>{{ $stateLabel($n) }}</td>
                            <td>{{ $n->outcome ? __(\App\Services\Calls\CallListService::OUTCOMES[$n->outcome] ?? $n->outcome) : '' }}</td>
                            <td class="n {{ $n->satisfaction !== null && $n->satisfaction < 50 ? 'bad' : '' }}">{{ $n->satisfaction === null ? '' : $n->satisfaction.'%' }}</td>
                            <td class="good">{{ $topicText($n->topics, 'good') }}</td>
                            <td class="bad">{{ $topicText($n->topics, 'bad') }}</td>
                            <td>{{ $n->note }}</td>
                            <td class="n">{{ $n->attempts }}</td>
                            <td>@if ($n->attempts){{ $n->by }} · {{ \Illuminate\Support\Carbon::parse($n->updated_at)->format('d M, g:i A') }}@endif</td>
                            <td>@if ($n->order_no){{ $n->order_no }} · ৳{{ number_format((float) $n->grand_total) }}@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
</x-print-page>
