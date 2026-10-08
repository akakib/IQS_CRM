{{-- Payments: money in to check, money owed back. @include with ['active' => 'check'|'refunds'] --}}
@php($owedN = \Illuminate\Support\Facades\DB::table('orders')->where('refund_due', '>', 0)->count())
<x-tabs :tabs="[
    'check' => [__('Payments to check'), route('payments.index')],
    'refunds' => [__('Refunds'), route('refunds.index'), $owedN ?: null],
]" :active="$active" />
